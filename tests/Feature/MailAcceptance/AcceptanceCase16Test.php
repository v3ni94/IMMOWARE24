<?php

declare(strict_types=1);

namespace Tests\Feature\MailAcceptance;

use App\Core\Enums\Role;
use App\Core\Support\OrganizationContext;
use App\Modules\Ai\Contracts\AiContextSourceInterface;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Drive\Models\DocumentReference;
use App\Modules\Drive\Services\DriveAiContextSource;
use App\Modules\Drive\Services\DriveSearchService;
use App\Modules\MailUi\Services\CaseVisibility;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Cases\CasesTestCase;

/**
 * Abnahmefall 16: Unberechtigter Benutzer erhält weder Mail- noch Drive-Inhalte, auch nicht über Suche oder KI-Kontext.
 *
 * Unberechtigt heißt: gültig angemeldet, 2FA bestätigt, gleiche Organisation, aber ohne Postfachrecht, Team oder
 * Zuweisung. Geprüft werden Vorgangsansicht (Mailinhalt), Posteingang mit Suchbegriff, Arbeitsliste, Drive-Suche
 * und KI-Kontextquelle. Der Auszug bleibt für Berechtigte erhalten. Ein Nutzer einer fremden Organisation sieht
 * ebenfalls nichts.
 */
final class AcceptanceCase16Test extends CasesTestCase
{
    private const string BASE = 'https://mail.muellerhv.de';

    private const string SECRET_SUBJECT = 'Hausgeldabrechnung Musterweg 3 Nachzahlung';

    private const string SECRET_BODY = 'Vertraulich: Nachzahlung 1.234,56 EUR bis 30.09.2026 ueberweisen.';

    private const string SECRET_EXCERPT = 'Drive-Auszug: Hausgeldabrechnung 2025 Musterweg 3, offener Betrag 1.234,56 EUR';

    public function test_unauthorized_user_gets_no_mail_or_drive_content_via_pages_search_or_ai_context(): void
    {
        Queue::fake();
        Http::fake();
        $this->app->bind(AiContextSourceInterface::class, DriveAiContextSource::class);

        $agent = $this->actingAsMailRole('agent');
        $organizationId = (int) $this->mailbox->organization_id;
        $message = $this->inboundMessage($this->mailbox, ['subject' => self::SECRET_SUBJECT, 'body_text' => self::SECRET_BODY]);
        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'rechnung', 'title' => self::SECRET_SUBJECT, 'assignee_user_id' => $agent->getKey()]], $agent);
        $this->assertTrue(CaseMessage::query()->where('case_id', $case->getKey())->where('message_id', $message->getKey())->exists());

        DocumentReference::query()->create([
            'organization_id' => $organizationId,
            'case_id' => $case->getKey(),
            'source' => 'drive',
            'drive_file_id' => 'doc-16',
            'name' => 'Abrechnung 2025',
            'mime_type' => 'application/vnd.google-apps.document',
            'text_excerpt' => self::SECRET_EXCERPT,
            'excerpt_indexed_at' => now(),
            'access_status' => 'ok',
        ]);

        // Berechtigte Sachbearbeitung sieht Vorgang, Mailinhalt und Treffer im Posteingang.
        $this->get(self::BASE.'/cases/'.$case->getKey())->assertOk()->assertSee(self::SECRET_SUBJECT)->assertSee('Nachzahlung 1.234,56 EUR');
        $this->get(self::BASE.'/inbox?q=Hausgeldabrechnung')->assertOk()->assertSee($case->case_number);

        // Unberechtigt: gleiche Organisation, gültige Sitzung mit 2FA, aber kein Postfachrecht, kein Team, keine Zuweisung.
        $outsider = User::factory()->role(Role::Operator)->create(['organization_id' => $organizationId, 'email' => 'fremd@muellerhv.de']);
        $this->assertFalse($this->app->make(CaseVisibility::class)->canView($outsider, $case));

        $this->actingAs($outsider)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        $this->get(self::BASE.'/cases/'.$case->getKey())->assertForbidden();

        $inbox = $this->get(self::BASE.'/inbox?q=Hausgeldabrechnung&status=');
        $inbox->assertOk()->assertDontSee($case->case_number)->assertDontSee(self::SECRET_SUBJECT)->assertDontSee('1.234,56');

        // Suche nach der Vorgangsnummer: nur das Suchfeld gibt die Eingabe zurück, die Trefferliste bleibt leer.
        $this->get(self::BASE.'/inbox?q='.rawurlencode($case->case_number).'&status=')->assertOk()
            ->assertSee('Keine Vorgänge für diese Auswahl.')->assertDontSee(self::SECRET_SUBJECT)->assertDontSee('/cases/'.$case->getKey());
        $this->get(self::BASE.'/work')->assertOk()->assertDontSee($case->case_number)->assertDontSee(self::SECRET_SUBJECT);

        // Drive-Suche und KI-Kontextquelle liefern nichts, ohne einen Aufruf nach außen.
        $this->assertSame([], $this->app->make(DriveSearchService::class)->search($outsider, $case, 'Nachzahlung'));
        $this->assertSame([], $this->app->make(DriveSearchService::class)->search($outsider, $case, ''));
        $this->assertSame([], $this->app->make(AiContextSourceInterface::class)->excerptsFor($case, $outsider, 5, 1500));
        Http::assertNothingSent();

        // Kein Rechteentzug in Drive durch die abgelehnte Abfrage: Auszug bleibt für Berechtigte im Index.
        $this->assertSame(self::SECRET_EXCERPT, DocumentReference::query()->withoutGlobalScopes()->where('drive_file_id', 'doc-16')->value('text_excerpt'));

        // Fremde Organisation: ebenfalls nichts.
        $foreign = User::factory()->role(Role::Administrator)->create(['email' => 'admin@fremd.example']);
        $this->actingAs($foreign)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
        $this->app->make(OrganizationContext::class)->set((int) $foreign->getAttribute('organization_id'));

        $status = $this->get(self::BASE.'/cases/'.$case->getKey())->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->get(self::BASE.'/inbox?q=Hausgeldabrechnung&status=')->assertOk()->assertDontSee($case->case_number)->assertDontSee(self::SECRET_SUBJECT);
        $this->assertSame([], $this->app->make(DriveSearchService::class)->search($foreign, MailCase::query()->withoutGlobalScopes()->findOrFail($case->getKey()), 'Nachzahlung'));
        Http::assertNothingSent();
    }
}
