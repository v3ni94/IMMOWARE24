<?php

declare(strict_types=1);

namespace Tests\Feature\Drive;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Core\Enums\Role;
use App\Modules\Ai\Contracts\AiContextSourceInterface;
use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Services\AiSuggestionService;
use App\Modules\Ai\Testing\FakeAiProvider;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Drive\Models\DocumentReference;
use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Drive\Models\DriveFolderMapping;
use App\Modules\Drive\Services\DriveAiContextSource;
use App\Modules\Drive\Services\DriveSearchService;
use App\Modules\Estate\Models\Property;
use App\Modules\MailUi\Services\CaseVisibility;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Abnahmefall 16: unberechtigte Personen erhalten keine Drive-Auszüge, weder über die Suche noch im KI-Kontext.
 * Rechteentzug in Drive entfernt den Suchindex-Auszug.
 */
final class DriveAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private MailCase $case;

    private DriveConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.drive.oauth.client_id', 'client-id');
        config()->set('hub.drive.oauth.client_secret', 'client-secret');
        config()->set('hub.mail.flags.ai', true);
        config()->set('hub.ai.budget', ['daily_cents' => 0, 'monthly_cents' => 0, 'daily_tokens' => 0, 'monthly_tokens' => 0]);
        $this->app->bind(AiContextSourceInterface::class, DriveAiContextSource::class);

        $this->agent = $this->actingAsMailRole('agent');
        $this->agent->forceFill(['email' => 'anna@muellerhv.de'])->save();
        $organizationId = (int) $this->agent->getAttribute('organization_id');

        $this->connection = DriveConnection::query()->create([
            'organization_id' => $organizationId, 'label' => 'Ablage', 'oauth_refresh_token' => 'r', 'oauth_access_token' => 'access',
            'oauth_token_expires_at' => now()->addHour(), 'status' => 'active',
        ]);

        $property = Property::factory()->create(['organization_id' => $organizationId, 'immoware_object_number' => 'OBJ-0007']);
        DriveFolderMapping::query()->create(['organization_id' => $organizationId, 'property_id' => $property->getKey(), 'folder_id' => 'ordner-obj7', 'drive_connection_id' => $this->connection->getKey()]);

        $this->case = MailCase::query()->create([
            'organization_id' => $organizationId, 'mailbox_id' => $this->mailbox?->getKey(), 'team_id' => $this->mailbox?->team_id,
            'case_number' => 'V-2026-0002', 'title' => 'Abrechnung', 'opened_at' => now(), 'property_id' => $property->getKey(),
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $permissions
     */
    private function fakeDrive(array $permissions): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/doc1/permissions*' => Http::response(['permissions' => $permissions]),
            'https://www.googleapis.com/drive/v3/files/doc1/export*' => Http::response('Hausgeldabrechnung 2025: Nachzahlung 1.234,56 EUR'),
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => [
                ['id' => 'doc1', 'name' => 'Abrechnung 2025', 'mimeType' => 'application/vnd.google-apps.document', 'webViewLink' => 'https://docs.google.com/d/doc1'],
                ['id' => 'sub', 'name' => 'Unterordner', 'mimeType' => 'application/vnd.google-apps.folder'],
            ]]),
        ]);
    }

    private function search(): DriveSearchService
    {
        return $this->app->make(DriveSearchService::class);
    }

    public function test_index_uses_mapped_folder_and_authorized_user_gets_excerpt_via_search_and_ai_context(): void
    {
        $this->fakeDrive([['type' => 'user', 'role' => 'reader', 'emailAddress' => 'anna@muellerhv.de']]);

        $references = $this->search()->indexCase($this->case, $this->agent);

        $this->assertCount(1, $references, 'Ordner selbst werden nicht referenziert.');
        $this->assertSame('doc1', $references[0]->getAttribute('drive_file_id'));
        $this->assertStringContainsString('Hausgeldabrechnung', (string) $references[0]->getAttribute('text_excerpt'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'q=') && str_contains(urldecode($request->url()), "'ordner-obj7' in parents"));

        $results = $this->search()->search($this->agent, $this->case, 'Nachzahlung');
        $this->assertCount(1, $results);
        $this->assertStringContainsString('1.234,56 EUR', (string) $results[0]['excerpt']);
        $this->assertSame('ok', $results[0]['reference']->fresh()?->getAttribute('access_status'));

        $context = $this->app->make(AiContextSourceInterface::class)->excerptsFor($this->case, $this->agent, 5, 20);
        $this->assertCount(1, $context);
        $this->assertSame(20, mb_strlen($context[0]['content']), 'Auszug begrenzter Länge.');
        $this->assertSame('drive', $context[0]['source']);
    }

    public function test_user_without_case_access_gets_nothing_via_search_or_ai(): void
    {
        $this->fakeDrive([['type' => 'anyone', 'role' => 'reader']]);
        $this->search()->indexCase($this->case, $this->agent);

        // Nutzer derselben Organisation, aber ohne Postfachfreigabe, Team oder Zuweisung.
        $outsider = User::factory()->role(Role::Operator)->create(['organization_id' => $this->agent->getAttribute('organization_id'), 'email' => 'fremd@muellerhv.de']);

        $this->assertSame([], $this->search()->search($outsider, $this->case, 'Nachzahlung'));
        $this->assertSame([], $this->app->make(AiContextSourceInterface::class)->excerptsFor($this->case, $outsider, 5, 1500));

        $ai = $this->app->make(AiProviderInterface::class);
        $this->assertInstanceOf(FakeAiProvider::class, $ai);
        $ai->setDefault('summarize', ['summary' => 'x', 'requests' => [], 'open_questions' => [], 'quotes' => []]);
        $this->app->make(AiSuggestionService::class)->run(AiTask::Summarize, $this->case, null, $outsider, [], [['type' => 'email', 'content' => 'Frage zur Abrechnung']]);

        $sent = json_encode($ai->calls('summarize')[0]['input'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Hausgeldabrechnung', $sent, 'Kein Drive-Auszug im KI-Kontext ohne Vorgangsrecht.');
        $this->assertCount(1, $ai->calls('summarize')[0]['input']['untrusted']);

        // Auszug bleibt für Berechtigte erhalten: fehlendes Anwendungsrecht ist kein Rechteentzug in Drive.
        $this->assertNotNull(DocumentReference::query()->withoutGlobalScopes()->where('drive_file_id', 'doc1')->first()?->getAttribute('text_excerpt'));
    }

    public function test_assignee_without_mailbox_read_permission_gets_no_excerpt_like_case_visibility(): void
    {
        $this->fakeDrive([['type' => 'anyone', 'role' => 'reader']]);
        $this->search()->indexCase($this->case, $this->agent);

        // Zugewiesen, aber ohne Postfachfreigabe can_read: die Oberfläche (CaseVisibility::canView) verweigert den Vorgang.
        $assignee = User::factory()->role(Role::Operator)->create(['organization_id' => $this->agent->getAttribute('organization_id'), 'email' => 'zugewiesen@muellerhv.de']);
        $this->case->forceFill(['assignee_user_id' => $assignee->getKey()])->save();

        $this->assertFalse($this->app->make(CaseVisibility::class)->canView($assignee, $this->case->fresh()));
        $this->assertSame([], $this->search()->search($assignee, $this->case->fresh(), 'Nachzahlung'), 'Zuweisung ersetzt die Postfachfreigabe nicht.');
        $this->assertSame([], $this->app->make(AiContextSourceInterface::class)->excerptsFor($this->case->fresh(), $assignee, 5, 1500));
    }

    public function test_user_with_case_access_but_without_drive_permission_gets_nothing_and_index_excerpt_is_removed(): void
    {
        $this->fakeDrive([['type' => 'user', 'role' => 'reader', 'emailAddress' => 'chef@muellerhv.de']]);
        $this->search()->indexCase($this->case, $this->agent);
        $reference = DocumentReference::query()->withoutGlobalScopes()->where('drive_file_id', 'doc1')->firstOrFail();
        $this->assertNotNull($reference->getAttribute('text_excerpt'));

        $results = $this->search()->search($this->agent, $this->case, 'Nachzahlung');

        $this->assertCount(1, $results, 'Der Verweis bleibt sichtbar.');
        $this->assertNull($results[0]['excerpt'], 'Kein Auszug ohne Drive-Berechtigung.');

        $reference->refresh();
        $this->assertNull($reference->getAttribute('text_excerpt'), 'Rechteentzug entfernt den Suchindex-Auszug.');
        $this->assertNull($reference->getAttribute('excerpt_hash'));
        $this->assertSame('revoked', $reference->getAttribute('access_status'));

        $this->assertSame([], $this->app->make(AiContextSourceInterface::class)->excerptsFor($this->case, $this->agent, 5, 1500));

        $ai = $this->app->make(AiProviderInterface::class);
        $this->assertInstanceOf(FakeAiProvider::class, $ai);
        $ai->setDefault('summarize', ['summary' => 'x', 'requests' => [], 'open_questions' => [], 'quotes' => []]);
        $this->app->make(AiSuggestionService::class)->run(AiTask::Summarize, $this->case, null, $this->agent, [], [['type' => 'email', 'content' => 'Frage']]);
        $this->assertStringNotContainsString('Hausgeldabrechnung', json_encode($ai->calls('summarize')[0]['input'], JSON_THROW_ON_ERROR));
    }

    public function test_revoke_file_removes_all_excerpts(): void
    {
        $this->fakeDrive([['type' => 'anyone', 'role' => 'reader']]);
        $this->search()->indexCase($this->case, $this->agent);

        $this->assertSame(1, $this->search()->revokeFile('doc1'));
        $this->assertNull(DocumentReference::query()->withoutGlobalScopes()->where('drive_file_id', 'doc1')->first()?->getAttribute('text_excerpt'));
        $this->assertSame(0, $this->search()->revokeFile('doc1'));
    }

    public function test_permission_check_failure_yields_no_excerpt(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/doc1/permissions*' => Http::response('down', 500),
            'https://www.googleapis.com/drive/v3/files/doc1/export*' => Http::response('Hausgeldabrechnung 2025'),
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => [
                ['id' => 'doc1', 'name' => 'Abrechnung 2025', 'mimeType' => 'application/vnd.google-apps.document'],
            ]]),
        ]);
        $this->search()->indexCase($this->case, $this->agent);
        $reference = DocumentReference::query()->withoutGlobalScopes()->where('drive_file_id', 'doc1')->firstOrFail();

        $this->assertNull($this->search()->authorizedExcerpt($this->agent, $this->case, $reference), 'Nicht prüfbar heißt kein Auszug, nie ein stiller Erfolg.');
        $this->assertNotNull($reference->fresh()?->getAttribute('text_excerpt'), 'Ein technischer Fehler ist kein Rechteentzug.');
    }

    public function test_not_configured_status_when_no_connection_exists(): void
    {
        $this->connection->forceFill(['oauth_refresh_token' => null])->save();

        $this->assertSame('not_configured', $this->search()->status());
        $this->assertSame([], $this->app->make(AiContextSourceInterface::class)->excerptsFor($this->case, $this->agent, 5, 1500));
    }
}
