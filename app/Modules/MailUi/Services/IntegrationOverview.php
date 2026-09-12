<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Core\Contracts\Mail\DocumentSourceInterface;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Lexware\Models\LexwareConnection;
use App\Modules\Lexware\Services\LexwareConnectionResolver;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Services\IntegrationStatusService;
use App\Modules\Mail\Services\MailFeatureFlags;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;

/**
 * Sichtbarer Zustand jeder Verbindung für die Integrationsseite und das Dashboard. Zustände: Nicht eingerichtet,
 * Eingerichtet (ungeprüft), Autorisierung unvollständig, Testbetrieb (Fake), Verbunden, Fehler, Reauth nötig.
 * Verbunden gibt es nur mit vorhandenem Zugang (Refresh-Token) und mindestens einem erfolgreichen Aufruf. Ein technischer Fehler wird nie als Erfolg gezeigt; ein bestätigter Watch ist erst
 * "aktiv", wenn watch_confirmed_at gesetzt ist. Buttons verweisen auf Routen der Fachmodule (config
 * hub.mailui.integration_routes); fehlt die Route, ist der Button deaktiviert.
 */
final class IntegrationOverview
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string CONNECTED = 'connected';

    public const string ERROR = 'error';

    public const string REAUTH = 'reauth';

    // Zugangsdaten hinterlegt, aber noch kein erfolgreicher Aufruf: nie als "Verbunden" ausweisen (docs/mail/03 Abschnitt 1).
    public const string UNVERIFIED = 'unverified';

    // OAuth-Rückruf ohne Refresh-Token (GoogleOAuthService setzt status configured): kein dauerhafter Zugang.
    public const string INCOMPLETE = 'incomplete';

    // Fake-Provider gebunden: Testbetrieb, kein Live-Betrieb.
    public const string FAKE = 'fake';

    /** @var array<string, array{label: string, symbol: string, level: string}> */
    public const array STATES = [
        self::NOT_CONFIGURED => ['label' => 'Nicht eingerichtet', 'symbol' => '○', 'level' => 'disabled'],
        self::CONNECTED => ['label' => 'Verbunden', 'symbol' => '●', 'level' => 'ok'],
        self::UNVERIFIED => ['label' => 'Eingerichtet (ungeprüft)', 'symbol' => '◐', 'level' => 'warn'],
        self::INCOMPLETE => ['label' => 'Autorisierung unvollständig', 'symbol' => '▲', 'level' => 'warn'],
        self::FAKE => ['label' => 'Testbetrieb (Fake)', 'symbol' => '◇', 'level' => 'warn'],
        self::ERROR => ['label' => 'Fehler', 'symbol' => '■', 'level' => 'fail'],
        self::REAUTH => ['label' => 'Reauth nötig', 'symbol' => '▲', 'level' => 'warn'],
    ];

    public function __construct(
        private readonly IntegrationStatusService $status,
        private readonly MailFeatureFlags $flags,
        private readonly Repository $config,
        private readonly Router $router,
        private readonly Container $container,
        private readonly LexwareConnectionResolver $lexware,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function connections(int $organizationId): array
    {
        $rows = [];

        foreach (Mailbox::query()->allOrganizations()->where('organization_id', $organizationId)->orderBy('label')->get() as $mailbox) {
            $rows[] = $this->mailboxRow($mailbox);
        }

        foreach (LexwareConnection::query()->allOrganizations()->where('organization_id', $organizationId)->orderBy('label')->get() as $connection) {
            $rows[] = $this->genericRow('lexware', 'Lexware Office: '.$connection->getAttribute('label'), $connection->getAttribute('status'), $connection, [
                'Schreiben' => (bool) $connection->getAttribute('write_enabled') && $this->flags->lexwareWriteEnabled() ? 'aktiv' : 'gesperrt (Flag oder Verbindung)',
            ], $connection->getAttribute('api_key_fingerprint') !== null ? ['API-Key (Fingerabdruck '.$connection->getAttribute('api_key_fingerprint').')'] : []);
        }

        foreach (DriveConnection::query()->allOrganizations()->where('organization_id', $organizationId)->orderBy('label')->get() as $connection) {
            $scopes = $connection->getAttribute('oauth_scopes_json');
            $rows[] = $this->genericRow('drive', 'Google Drive: '.$connection->getAttribute('label'), $connection->getAttribute('status'), $connection, [
                'Lesen' => 'nur lesend',
            ], is_array($scopes) ? array_map('strval', $scopes) : []);
        }

        foreach (ImmowareConnection::query()->allOrganizations()->where('organization_id', $organizationId)->orderBy('name')->get() as $connection) {
            $status = (string) $connection->getAttribute('status');
            $state = match ($status) {
                'active' => self::CONNECTED,
                'degraded', 'failed', 'error' => self::ERROR,
                default => self::NOT_CONFIGURED,
            };
            $rows[] = [
                'integration' => 'immoware24',
                'title' => 'Immoware24: '.$connection->getAttribute('name'),
                'state' => $state,
                'state_reason' => $status === 'active' ? null : 'Connector-Status '.$status,
                'scopes' => [(string) $connection->getAttribute('connector_type')],
                'capabilities' => ['Lesen' => 'DAV-Spiegel', 'Schreiben' => (bool) $connection->getAttribute('write_enabled') && $this->flags->immowareWriteEnabled() ? 'Posteingang-Upload (create-only)' : 'gesperrt'],
                'last_success_at' => $connection->getAttribute('last_probe_at'),
                'lag' => null,
                'error' => null,
                'actions' => [],
            ];
        }

        foreach (['lexware' => 'Lexware Office', 'drive' => 'Google Drive', 'ai' => 'KI-Vorschläge (OpenAI)'] as $key => $name) {
            $hasRows = array_filter($rows, static fn (array $row): bool => $row['integration'] === $key) !== [];

            if ($key === 'ai' || ! $hasRows) {
                $mode = $this->status->providerMode($key);
                [$state, $reason] = $this->providerState($key, $mode, $organizationId);
                $rows[] = [
                    'integration' => $key,
                    'title' => $name,
                    'state' => $state,
                    'state_reason' => $reason ?? ($key === 'ai' && ! $this->flags->aiEnabled() ? 'Flag MAIL_AI_ENABLED=false' : null),
                    'scopes' => [],
                    'capabilities' => $key === 'ai' ? ['Vorschläge' => $this->flags->aiEnabled() ? 'nur Vorschlag, Bestätigung durch Mensch' : 'aus'] : [],
                    'last_success_at' => null,
                    'lag' => null,
                    'error' => null,
                    'actions' => $this->actions($key, null),
                ];
            }
        }

        return $rows;
    }

    /**
     * Blockierte Integrationen (alles außer Verbunden) für das Dashboard. Ungeprüft, unvollständig und Fake zählen
     * als blockiert: ohne erfolgreichen Aufruf gilt keine Integration als verbunden.
     *
     * @return array<int, array<string, mixed>>
     */
    public function blocked(int $organizationId): array
    {
        return array_values(array_filter($this->connections($organizationId), static fn (array $row): bool => $row['state'] !== self::CONNECTED));
    }

    /**
     * Zustand aus der tatsächlich gebundenen Implementierung, nicht allein aus hub.mail.providers: live ohne
     * Zugangsdaten (AiServiceProvider bindet NotConfiguredAiProvider, DriveProvider ohne Refresh-Token, Lexware ohne
     * API-Key) ist Nicht eingerichtet. Mit Zugangsdaten und ohne erfolgreichen Aufruf höchstens ungeprüft.
     *
     * @return array{0: string, 1: ?string}
     */
    private function providerState(string $integration, string $mode, int $organizationId): array
    {
        if ($mode === IntegrationStatusService::NOT_CONFIGURED) {
            return [self::NOT_CONFIGURED, null];
        }

        if ($mode === IntegrationStatusService::FAKE) {
            return [self::FAKE, 'Testbetrieb (Fake), kein Live-Betrieb'];
        }

        $configured = match ($integration) {
            'lexware' => $this->lexware->status($organizationId) === LexwareConnectionResolver::STATUS_CONFIGURED,
            'drive' => $this->boundIsConfigured(DocumentSourceInterface::class),
            'ai' => $this->boundIsConfigured(AiProviderInterface::class),
            default => false,
        };

        if (! $configured) {
            return [self::NOT_CONFIGURED, 'Provider live, aber keine Zugangsdaten hinterlegt (Nicht eingerichtet).'];
        }

        return [self::UNVERIFIED, 'Zugangsdaten hinterlegt, noch kein erfolgreicher Aufruf.'];
    }

    private function boundIsConfigured(string $abstract): bool
    {
        try {
            $implementation = $this->container->make($abstract);
        } catch (\Throwable) {
            return false;
        }

        return method_exists($implementation, 'isConfigured') && (bool) $implementation->isConfigured();
    }

    /**
     * @return array<string, mixed>
     */
    private function mailboxRow(Mailbox $mailbox): array
    {
        $status = (string) $mailbox->getAttribute('status');
        $sync = MailSyncState::query()->where('mailbox_id', $mailbox->getKey())->first();
        $expires = $mailbox->getAttribute('oauth_token_expires_at');

        $hasRefreshToken = $mailbox->getAttribute('oauth_refresh_token') !== null;

        // configured setzt GoogleOAuthService genau dann, wenn Google kein Refresh-Token geliefert hat: kein Zugang.
        $state = match ($status) {
            'active' => $hasRefreshToken ? self::CONNECTED : self::REAUTH,
            'configured' => self::INCOMPLETE,
            'degraded' => self::ERROR,
            'revoked' => self::REAUTH,
            default => self::NOT_CONFIGURED,
        };

        if ($state === self::CONNECTED && ! $hasRefreshToken && $expires instanceof CarbonImmutable && $expires->isPast()) {
            $state = self::REAUTH;
        }

        $lag = [];

        if ($sync !== null) {
            $updated = $sync->getAttribute('history_id_updated_at');
            $lag['History-Stand'] = $updated instanceof CarbonImmutable ? $updated->diffForHumans(now(), ['syntax' => CarbonImmutable::DIFF_ABSOLUTE]).' alt' : 'kein Abgleich';
            $watchExp = $sync->getAttribute('watch_expiration');
            $lag['Watch'] = (string) $sync->getAttribute('watch_status').($watchExp instanceof CarbonImmutable ? ', läuft ab in '.$watchExp->diffForHumans(now(), ['syntax' => CarbonImmutable::DIFF_ABSOLUTE]) : '');

            if ((string) $sync->getAttribute('watch_status') === 'expired' && $state === self::CONNECTED) {
                $state = self::ERROR;
            }
        } else {
            $lag['History-Stand'] = 'kein Abgleich';
        }

        $scopes = $mailbox->getAttribute('oauth_scopes_json');
        $scopes = is_array($scopes) ? array_map('strval', $scopes) : [];
        $error = $mailbox->getAttribute('last_error_at') !== null ? (string) $mailbox->getAttribute('last_error_class') : null;

        return [
            'integration' => 'gmail',
            'title' => 'Gmail: '.$mailbox->getAttribute('label').' ('.$mailbox->getAttribute('email_address').')',
            'state' => $state,
            'state_reason' => $mailbox->getAttribute('status_reason'),
            'scopes' => $scopes,
            'capabilities' => [
                'Import' => (bool) $mailbox->getAttribute('import_enabled') && $this->flags->importEnabled() ? 'aktiv' : 'aus',
                'Entwürfe' => $this->gmailCapability('drafts', $this->flags->gmailDraftsEnabled(), $scopes, 'aktiv'),
                'Versand' => $this->gmailCapability('send', $this->flags->gmailSendEnabled(), $scopes, 'aktiv (Freigabe und Reauth erforderlich)'),
            ],
            'last_success_at' => $sync?->getAttribute('last_incremental_at') ?? $sync?->getAttribute('full_sync_finished_at'),
            'lag' => $lag,
            'error' => $error,
            'actions' => $this->actions('gmail', $mailbox),
        ];
    }

    /**
     * Eine Fähigkeit gilt nur als aktiv, wenn Flag gesetzt und alle dafür nötigen Scopes (config
     * hub.gmail.oauth.functions) tatsächlich erteilt wurden. Flag ohne Scope wäre eine suggerierte Fähigkeit.
     *
     * @param  array<int, string>  $granted
     */
    private function gmailCapability(string $function, bool $flag, array $granted, string $activeLabel): string
    {
        if (! $flag) {
            return $function === 'send' ? 'gesperrt' : 'aus';
        }

        $scopeMap = (array) $this->config->get('hub.gmail.oauth.scopes', []);
        $missing = [];

        foreach ((array) $this->config->get('hub.gmail.oauth.functions.'.$function, []) as $key) {
            $scope = (string) ($scopeMap[$key] ?? '');

            if ($scope !== '' && ! in_array($scope, $granted, true)) {
                $missing[] = (string) $key;
            }
        }

        return $missing === [] ? $activeLabel : 'Flag gesetzt, Scope fehlt ('.implode(', ', $missing).'), erneut autorisieren';
    }

    /**
     * @param  array<string, string>  $capabilities
     * @param  array<int, string>  $scopes
     * @return array<string, mixed>
     */
    private function genericRow(string $integration, string $title, mixed $status, object $connection, array $capabilities, array $scopes): array
    {
        $status = (string) $status;
        $state = match ($status) {
            'active' => $connection->getAttribute('last_success_at') !== null ? self::CONNECTED : self::UNVERIFIED,
            'configured' => self::UNVERIFIED,
            'degraded' => self::ERROR,
            'revoked' => self::REAUTH,
            default => self::NOT_CONFIGURED,
        };

        return [
            'integration' => $integration,
            'title' => $title,
            'state' => $state,
            'state_reason' => $connection->getAttribute('status_reason') ?? ($state === self::UNVERIFIED ? 'Zugangsdaten hinterlegt, noch kein erfolgreicher Aufruf.' : null),
            'scopes' => $scopes,
            'capabilities' => $capabilities,
            'last_success_at' => $connection->getAttribute('last_success_at'),
            'lag' => null,
            'error' => $connection->getAttribute('last_error_at') !== null ? (string) $connection->getAttribute('last_error_class') : null,
            'actions' => $this->actions($integration, $connection instanceof Mailbox ? $connection : null),
        ];
    }

    /**
     * Verbinden/Widerrufen als Links auf Routen der Fachmodule. Fehlt die Route, bleibt der Button deaktiviert.
     *
     * @return array<int, array{label: string, url: ?string, method: string}>
     */
    private function actions(string $integration, ?Mailbox $mailbox): array
    {
        $routes = (array) $this->config->get('hub.mailui.integration_routes.'.$integration, []);
        $result = [];

        foreach (['connect' => 'Verbinden', 'revoke' => 'Widerrufen'] as $action => $label) {
            $route = $routes[$action] ?? null;
            $url = null;

            if (is_string($route) && $this->router->has($route)) {
                $parameters = $mailbox !== null ? ['mailbox' => $mailbox->getKey()] : [];

                try {
                    $url = route($route, $parameters);
                } catch (\Throwable) {
                    $url = null;
                }
            }

            $result[] = ['label' => $label, 'url' => $url, 'method' => 'post'];
        }

        return $result;
    }
}
