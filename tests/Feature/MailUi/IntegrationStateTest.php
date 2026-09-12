<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Lexware\Models\LexwareConnection;
use App\Modules\Mail\Services\IntegrationStatusService;
use App\Modules\MailUi\Services\IntegrationOverview;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integrationsstatus zeigt nie "Verbunden" ohne Verbindung (docs/mail/03 Abschnitt 1): configured ohne Refresh-Token,
 * live ohne Zugangsdaten und Fake sind eigene Zustände und blockieren das Dashboard.
 */
final class IntegrationStateTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_mailbox_configured_without_refresh_token_is_not_connected(): void
    {
        $admin = $this->actingAsMailRole('admin');
        $this->mailbox->forceFill(['status' => 'configured', 'oauth_refresh_token' => null, 'status_reason' => 'Google hat kein Refresh-Token geliefert'])->save();

        $rows = $this->app->make(IntegrationOverview::class)->connections((int) $admin->getAttribute('organization_id'));
        $gmail = collect($rows)->firstWhere('integration', 'gmail');

        $this->assertSame(IntegrationOverview::INCOMPLETE, $gmail['state']);
        $this->assertNotEmpty(array_filter($this->app->make(IntegrationOverview::class)->blocked((int) $admin->getAttribute('organization_id')), static fn (array $r): bool => $r['integration'] === 'gmail'));

        $this->get(self::BASE.'/integrations')->assertOk()->assertSee('Autorisierung unvollständig')->assertDontSee('Alle Integrationen verbunden');
        $this->get(self::BASE.'/admin/mailboxes')->assertOk()->assertSee('Autorisierung unvollständig');
        $this->get(self::BASE.'/')->assertOk()->assertDontSee('Alle Integrationen verbunden');

        // active mit Refresh-Token ist verbunden; active ohne Token verlangt Reauth.
        $this->mailbox->forceFill(['status' => 'active', 'oauth_refresh_token' => 'rt-1'])->save();
        $this->assertSame(IntegrationOverview::CONNECTED, $this->gmailState($admin));
        $this->mailbox->forceFill(['oauth_refresh_token' => null])->save();
        $this->assertSame(IntegrationOverview::REAUTH, $this->gmailState($admin));
    }

    public function test_live_provider_without_credentials_is_not_configured_and_fake_is_own_state(): void
    {
        $admin = $this->actingAsMailRole('admin');
        $orgId = (int) $admin->getAttribute('organization_id');

        config()->set('hub.mail.providers.ai', 'live');
        config()->set('hub.ai.api_key', null);
        config()->set('hub.ai.model', null);
        config()->set('hub.mail.providers.lexware', 'live');
        config()->set('hub.mail.providers.drive', 'fake');

        $rows = collect($this->app->make(IntegrationOverview::class)->connections($orgId));

        $this->assertSame(IntegrationOverview::NOT_CONFIGURED, $rows->firstWhere('integration', 'ai')['state']);
        $this->assertSame(IntegrationOverview::NOT_CONFIGURED, $rows->firstWhere('integration', 'lexware')['state']);
        $this->assertSame(IntegrationOverview::FAKE, $rows->firstWhere('integration', 'drive')['state']);

        // Lexware mit API-Key, aber ohne erfolgreichen Aufruf: ungeprüft, nicht verbunden.
        LexwareConnection::query()->create(['organization_id' => $orgId, 'label' => 'HVM', 'legal_entity_code' => 'HVM', 'base_url' => 'https://api.lexoffice.io/v1', 'api_key' => 'k', 'status' => 'configured']);
        $rows = collect($this->app->make(IntegrationOverview::class)->connections($orgId));
        $this->assertSame(IntegrationOverview::UNVERIFIED, $rows->firstWhere('integration', 'lexware')['state']);

        $this->get(self::BASE.'/integrations')->assertOk()->assertSee('Eingerichtet (ungeprüft)')->assertSee('Testbetrieb (Fake)');
    }

    public function test_immoware_status_follows_connections(): void
    {
        $status = $this->app->make(IntegrationStatusService::class);

        $this->assertSame(IntegrationStatusService::NOT_CONFIGURED, $status->overview()['immoware24']['mode']);

        $connection = ImmowareConnection::factory()->create(['status' => 'paused', 'last_probe_at' => null]);
        $this->assertSame(IntegrationStatusService::UNVERIFIED, $status->overview()['immoware24']['mode']);

        $connection->forceFill(['status' => 'active', 'last_probe_at' => now()])->save();
        $this->assertSame(IntegrationStatusService::LIVE, $status->overview()['immoware24']['mode']);
    }

    private function gmailState(User $admin): string
    {
        $rows = $this->app->make(IntegrationOverview::class)->connections((int) $admin->getAttribute('organization_id'));

        return (string) collect($rows)->firstWhere('integration', 'gmail')['state'];
    }
}
