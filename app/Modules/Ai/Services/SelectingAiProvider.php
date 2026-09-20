<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Wählt unter mehreren eingerichteten KI-Anbietern anhand config hub.ai.provider_priority (Standard: openai vor
 * anthropic, Kostenentscheidung der Geschäftsführung). Ist der bevorzugte Anbieter nicht eingerichtet, wird er
 * übersprungen; ist er eingerichtet, aber bei diesem Aufruf nicht erreichbar (MailRemoteException), versucht diese
 * Klasse den nächsten eingerichteten Anbieter der Liste. Ist kein Anbieter eingerichtet, wirft sie
 * MailIntegrationNotConfiguredException. Welcher Anbieter tatsächlich geantwortet hat, steht in meta.provider
 * (AiSuggestionService schreibt das nach mail_ai_runs.provider).
 */
final class SelectingAiProvider implements AiProviderInterface
{
    /** @var array<string, AiProviderInterface> */
    private readonly array $providers;

    public function __construct(
        private readonly Repository $config,
        OpenAiProvider $openai,
        AnthropicProvider $anthropic,
    ) {
        $this->providers = ['openai' => $openai, 'anthropic' => $anthropic];
    }

    public function structured(string $task, array $input, array $schema): array
    {
        $order = $this->order();
        $lastNotConfigured = null;
        $lastRemote = null;

        foreach ($order as $name) {
            $provider = $this->providers[$name] ?? null;

            if ($provider === null || ! $provider->isConfigured()) {
                continue;
            }

            try {
                return $provider->structured($task, $input, $schema);
            } catch (MailIntegrationNotConfiguredException $e) {
                // isConfigured() prüft nur Modell und Schlüssel; ein Anbieter kann seinen eigenen Aufruf dennoch
                // als nicht eingerichtet ablehnen (z. B. weitere Pflichtangaben). Nächsten Anbieter versuchen.
                $lastNotConfigured = $e;

                continue;
            } catch (MailRemoteException $e) {
                Log::warning('KI-Anbieter nicht erreichbar, versuche nächsten Anbieter der Prioritätsliste', [
                    'task' => $task,
                    'provider' => $name,
                    'status' => $e->httpStatus,
                ]);
                $lastRemote = $e;

                continue;
            }
        }

        if ($lastRemote !== null) {
            throw $lastRemote;
        }

        throw $lastNotConfigured ?? MailIntegrationNotConfiguredException::for('ai');
    }

    /**
     * @return array<int, string>
     */
    private function order(): array
    {
        $configured = array_values(array_map('strval', (array) $this->config->get('hub.ai.provider_priority', ['openai', 'anthropic'])));
        $known = array_keys($this->providers);

        $order = array_values(array_intersect($configured, $known));

        // Unbekannte oder leere Konfiguration: alle bekannten Anbieter in Standardreihenfolge versuchen,
        // damit ein Tippfehler in provider_priority die KI nicht stumm abschaltet.
        return $order === [] ? $known : $order;
    }
}
