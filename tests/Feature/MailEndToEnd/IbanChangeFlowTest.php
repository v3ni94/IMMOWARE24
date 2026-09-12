<?php

declare(strict_types=1);

namespace Tests\Feature\MailEndToEnd;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ActionPlanService;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Actions\Services\ManualTaskService;
use App\Modules\Actions\Support\IbanValidator;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\Task;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Models\WriteOperation;
use Illuminate\Support\Facades\Http;

/**
 * End-to-End (b): IBAN im Mailtext wird extrahiert (gültige Prüfziffer, nur maskiert gespeichert), der Plan zur
 * Bankänderung ist ohne Identitätsprüfung gesperrt, verlangt zwei verschiedene Freigebende und endet, weil
 * Immoware24 nicht schreibfähig ist, in einer manuellen Aufgabe. Die Bestätigung setzt manually_confirmed. Es gibt
 * keinen Zahlungsvorgang, keine Schreiboperation, keinen Aufruf nach außen.
 */
final class IbanChangeFlowTest extends MailEndToEndTestCase
{
    public function test_iban_change_requires_identity_check_and_two_approvers_and_ends_in_manual_confirmation(): void
    {
        $newIban = 'DE02120300000000202051';

        $message = $this->receive([
            'from' => 'Erika Muster <erika.muster@example.com>',
            'subject' => 'Neue Bankverbindung',
            'text' => "Guten Tag,\nbitte buchen Sie ab sofort von meiner neuen IBAN DE02 1203 0000 0000 2020 51 ab.\nErika Muster",
        ]);

        // Extraktion mit gültiger Prüfziffer, Speicherung nur maskiert.
        $this->assertSame([$newIban], $this->app->make(IbanValidator::class)->extract((string) $message->getAttribute('body_text')));
        $case = $this->caseOf($message);
        $item = $case->items()->where('item_type', 'bankdaten')->firstOrFail();
        $this->assertStringContainsString('gültige Prüfziffer', (string) $item->getAttribute('description'));
        $this->assertStringNotContainsString($newIban, (string) $item->getAttribute('description'));
        $this->assertStringNotContainsString('2020 51', (string) $item->getAttribute('description'));
        $this->assertNotContains($case->priority, [Priority::P0, Priority::P1], 'Keine Notfallregel getroffen, Standardpriorität.');

        // Plan zur Bankänderung: Risikoklasse bank, zwei Freigaben, Identitätsprüfung Pflicht.
        $account = $this->seedBankAccount();
        $version = $this->app->make(ActionPlanService::class)->propose($case, [$this->bankChangeStep($account, $newIban)], $this->author, (int) $message->getKey());
        $this->assertSame(RiskClass::Bank, $version->plan->risk_class);
        $this->assertSame(2, (int) $version->required_approvals);
        $this->assertTrue((bool) $version->requires_identity_check);
        $this->approvals()->requestApproval($version, $this->author);
        $this->assertSame(ActionStatus::ApprovalRequired, $version->plan->fresh()->status);

        // Ohne Identitätsprüfung gesperrt, auch über die Oberfläche (Freigabecenter meldet Fehler, keine Freigabe).
        try {
            $this->approveBy($version, $this->approverOne);
            $this->fail('Freigabe ohne Identitätsprüfung darf nicht möglich sein.');
        } catch (ActionPolicyException $e) {
            $this->assertSame('identity_check_missing', $e->code_key);
        }

        $this->actingAs($this->approverOne)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post(self::BASE.'/approvals/'.$version->plan->getKey().'/approve', ['comment' => 'geprüft'])
            ->assertRedirect(self::BASE.'/approvals/'.$version->plan->getKey())
            ->assertSessionHas('error');
        $this->assertDatabaseCount('mail_approvals', 0);
        $this->assertDatabaseCount('mail_tasks', 0);

        // Identitätsprüfung über die Oberfläche dokumentieren, dann zwei verschiedene Freigebende.
        $this->post(self::BASE.'/approvals/'.$version->plan->getKey().'/identity-check', ['channel' => 'phone_callback', 'note' => 'Rückruf unter bekannter Nummer'])
            ->assertRedirect(self::BASE.'/approvals/'.$version->plan->getKey())
            ->assertSessionHas('status');
        $this->assertDatabaseCount('mail_identity_checks', 1);

        $this->approveBy($version, $this->approverOne);
        $this->assertSame(ActionStatus::ApprovalRequired, $version->plan->fresh()->status, 'Eine Freigabe reicht bei Bankdaten nicht.');
        $this->assertDatabaseCount('mail_tasks', 0);

        $this->approveBy($version, $this->approverTwo);

        // Immoware24 nicht schreibfähig: manuelle Aufgabe, ausgeführt heißt nicht verifiziert.
        $execution = Execution::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $this->assertSame(ExecutionService::STATUS_MANUAL_TASK, $execution->getAttribute('status'));
        $this->assertSame(ActionStatus::Executed, $version->plan->fresh()->status);
        $task = Task::query()->findOrFail($execution->getAttribute('task_id'));
        $this->assertSame('manual_change_immoware', $task->getAttribute('task_type'));
        $this->assertSame('open', $task->getAttribute('status'));
        $this->assertStringNotContainsString($newIban, (string) $task->getAttribute('instructions'));

        // Manuelle Bestätigung: manually_confirmed, Plan verifiziert, Aufgabe abgeschlossen.
        $verification = $this->app->make(ManualTaskService::class)->confirm($task, $this->approverTwo);
        $this->assertSame(VerificationStatus::ManuallyConfirmed, $verification->getAttribute('result'));
        $this->assertSame(VerificationStatus::ManuallyConfirmed, $execution->fresh()->getAttribute('verification_status'));
        $this->assertSame('done_manual_confirmed', $task->fresh()->getAttribute('status'));
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);

        // Kein Zahlungsvorgang, keine Schreiboperation, kein Aufruf nach außen.
        $this->assertSame(1, Execution::query()->count());
        $this->assertSame('immoware24', $execution->getAttribute('target_system'));
        $this->assertSame([], $this->lexware->requests('PUT'));
        $this->assertSame([], $this->lexware->requests('POST'));
        $this->assertSame(0, WriteOperation::query()->count(), 'Kein Schreibpfad Richtung Immoware24 (write_operations leer).');
        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->url(), 'lexoffice') || str_contains((string) $request->url(), 'gmail.googleapis'));
    }
}
