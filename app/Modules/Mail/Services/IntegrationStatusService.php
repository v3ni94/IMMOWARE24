<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Sichtbarer Status der Integrationen. Nicht eingerichtete Integrationen erscheinen als "Nicht eingerichtet",
 * fake gebundene als "Testbetrieb (Fake)". Ein technischer Fehler wird nie als Erfolg dargestellt.
 */
final class IntegrationStatusService
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string FAKE = 'fake';

    public const string LIVE = 'live';

    // Zugangsdaten hinterlegt, aber noch kein erfolgreicher Probe-Aufruf (docs/mail/03 Abschnitt 1).
    public const string UNVERIFIED = 'unverified';

    /** @var array<string, string> */
    public const array LABELS = [
        self::NOT_CONFIGURED => 'Nicht eingerichtet',
        self::FAKE => 'Testbetrieb (Fake)',
        self::LIVE => 'Eingerichtet',
        self::UNVERIFIED => 'Eingerichtet (ungeprüft)',
    ];

    /** @var array<string, string> */
    public const array INTEGRATIONS = [
        'gmail' => 'Gmail (Mailsystem)',
        'immoware24' => 'Immoware24 (Fachsystem)',
        'lexware' => 'Lexware Office (Rechnungsprogramm)',
        'drive' => 'Google Drive (Dokumentenquelle)',
        'ai' => 'KI-Vorschläge (OpenAI)',
    ];

    public function __construct(private readonly Repository $config) {}

    /**
     * Provider-Modus aus config hub.mail.providers.<integration>: live, fake oder null.
     */
    public function providerMode(string $integration): string
    {
        $value = $this->config->get('hub.mail.providers.'.$integration);

        if (! is_string($value) || trim($value) === '') {
            return self::NOT_CONFIGURED;
        }

        $value = strtolower(trim($value));

        return in_array($value, [self::FAKE, self::LIVE], true) ? $value : self::NOT_CONFIGURED;
    }

    public function isFake(string $integration): bool
    {
        return $this->providerMode($integration) === self::FAKE;
    }

    public function isConfigured(string $integration): bool
    {
        return $this->providerMode($integration) !== self::NOT_CONFIGURED;
    }

    public function label(string $integration): string
    {
        return self::LABELS[$this->providerMode($integration)];
    }

    /**
     * @return array<string, array{name: string, mode: string, label: string}>
     */
    public function overview(): array
    {
        $result = [];

        foreach (self::INTEGRATIONS as $key => $name) {
            $mode = $key === 'immoware24' ? $this->immowareMode() : $this->providerMode($key);
            $result[$key] = ['name' => $name, 'mode' => $mode, 'label' => self::LABELS[$mode]];
        }

        return $result;
    }

    /**
     * Immoware24 kennt keinen Provider-Modus; der Zustand folgt den Connections: keine Connection heißt Nicht
     * eingerichtet, aktive Connection mit erfolgreicher Probe heißt Eingerichtet, alles andere ungeprüft.
     */
    public function immowareMode(): string
    {
        try {
            $connections = ImmowareConnection::query()->allOrganizations()->get(['id', 'status', 'last_probe_at']);
        } catch (Throwable) {
            // Ohne Datenbank (Migrationen ausstehend) gibt es keine belegte Verbindung: Nicht eingerichtet, nie Eingerichtet.
            return self::NOT_CONFIGURED;
        }

        if ($connections->isEmpty()) {
            return self::NOT_CONFIGURED;
        }

        $probed = $connections->contains(static fn (ImmowareConnection $c): bool => (string) $c->getAttribute('status') === 'active' && $c->getAttribute('last_probe_at') !== null);

        return $probed ? self::LIVE : self::UNVERIFIED;
    }
}
