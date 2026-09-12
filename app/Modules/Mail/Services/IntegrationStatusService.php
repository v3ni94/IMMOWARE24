<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Sichtbarer Status der Integrationen. Nicht eingerichtete Integrationen erscheinen als "Nicht eingerichtet",
 * fake gebundene als "Testbetrieb (Fake)". Ein technischer Fehler wird nie als Erfolg dargestellt.
 */
final class IntegrationStatusService
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string FAKE = 'fake';

    public const string LIVE = 'live';

    /** @var array<string, string> */
    public const array LABELS = [
        self::NOT_CONFIGURED => 'Nicht eingerichtet',
        self::FAKE => 'Testbetrieb (Fake)',
        self::LIVE => 'Eingerichtet',
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
            $mode = $key === 'immoware24' ? self::LIVE : $this->providerMode($key);
            $result[$key] = ['name' => $name, 'mode' => $mode, 'label' => self::LABELS[$mode]];
        }

        return $result;
    }
}
