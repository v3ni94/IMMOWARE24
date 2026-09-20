<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Modules\Ai\Enums\AiTask;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic-Adapter über die Http-Facade (Messages API). Strukturierte Ausgabe über einen erzwungenen Tool-Aufruf
 * (tools mit einem Werkzeug "structured_output", dessen input_schema das Ausgabeschema ist, tool_choice erzwingt
 * genau dieses Werkzeug). Alle Feldnamen stammen aus Snippets und sind vor Inbetriebnahme am Original zu prüfen.
 * Kein Live-Test möglich, geprüft nur mit Http::fake().
 *
 * Zweiter Anbieter neben OpenAiProvider, ausgewählt über SelectingAiProvider anhand config hub.ai.provider_priority
 * (Standard: OpenAI vor Anthropic, Kostenentscheidung). Die Klasse ist reiner Transport: Maskierung, Budget,
 * Schema- und Fachprüfung sowie Protokoll erledigt AiSuggestionService. Der Modellname kommt ausschließlich aus
 * MAIL_AI_ANTHROPIC_MODEL; ohne Modell oder API-Key gilt "Nicht eingerichtet".
 */
final class AnthropicProvider implements AiProviderInterface
{
    public const string INTEGRATION = 'ai_anthropic';

    private const string TOOL_NAME = 'structured_output';

    public function __construct(
        private readonly Repository $config,
        private readonly PromptBuilder $prompts,
    ) {}

    public function isConfigured(): bool
    {
        return $this->model() !== null && $this->apiKey() !== null;
    }

    public function model(): ?string
    {
        $model = $this->config->get('hub.ai.anthropic.model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    public function structured(string $task, array $input, array $schema): array
    {
        $model = $this->model();
        $apiKey = $this->apiKey();

        if ($model === null || $apiKey === null) {
            throw MailIntegrationNotConfiguredException::for(self::INTEGRATION);
        }

        $aiTask = AiTask::tryFrom($task);

        if ($aiTask === null) {
            throw new MailRemoteException(sprintf('Unbekannte KI-Aufgabe %s.', $task), self::INTEGRATION);
        }

        $system = $this->prompts->systemInstruction($aiTask);
        $user = $this->prompts->userMessage($aiTask, $input);
        $started = hrtime(true);

        $response = $this->send($apiKey, $this->payload($model, $task, $system, $user, $schema));
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        $json = $response->json();

        if (! is_array($json)) {
            throw new MailRemoteException('KI-Antwort ist kein JSON.', self::INTEGRATION, $response->status(), $this->excerpt($response->body()));
        }

        $decoded = $this->extractToolInput($json, $response);
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];

        $decoded['meta'] = [
            'provider' => 'anthropic',
            'model' => (string) ($json['model'] ?? $model),
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'latency_ms' => $latency,
            'response_id' => isset($json['id']) ? (string) $json['id'] : null,
        ];

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function payload(string $model, string $task, string $system, string $user, array $schema): array
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $task);
        $maxOutput = (int) $this->config->get('hub.ai.anthropic.max_output_tokens', 2000);
        $temperature = $this->config->get('hub.ai.anthropic.temperature', 0);

        return [
            'model' => $model,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
            // Erzwungener Werkzeugaufruf statt freiem Text, damit die Antwort ausschließlich das Schema trifft
            // (aus Snippets: tools mit input_schema, tool_choice type tool, name).
            'tools' => [
                ['name' => self::TOOL_NAME, 'description' => 'Gibt die Antwort ausschließlich als dieses Objekt zurück.', 'input_schema' => $schema],
            ],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
            'temperature' => $temperature,
            'max_tokens' => $maxOutput,
            'metadata' => ['user_id' => 'hub_'.$name],
        ];
    }

    private function send(string $apiKey, array $payload): Response
    {
        $base = rtrim((string) $this->config->get('hub.ai.anthropic.base_url', 'https://api.anthropic.com/v1'), '/');
        $version = (string) $this->config->get('hub.ai.anthropic.version', '2023-06-01');
        $times = max(0, (int) $this->config->get('hub.ai.anthropic.retry.times', 2));
        $sleeps = array_values(array_map('intval', (array) $this->config->get('hub.ai.anthropic.retry.sleep_ms', [500, 2000])));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => $version])
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout((int) $this->config->get('hub.ai.anthropic.connect_timeout_seconds', 10))
                    ->timeout((int) $this->config->get('hub.ai.anthropic.timeout_seconds', 60))
                    ->post($base.'/messages', $payload);
            } catch (ConnectionException) {
                if ($attempt > $times) {
                    throw new MailRemoteException('KI-Anbieter nicht erreichbar (Transport).', self::INTEGRATION);
                }

                $this->pause($sleeps, $attempt);

                continue;
            }

            $status = $response->status();

            if ($status === 429 || $status >= 500) {
                if ($attempt > $times) {
                    throw new MailRemoteException(sprintf('KI-Anbieter antwortet mit HTTP %d.', $status), self::INTEGRATION, $status, $this->excerpt($response->body()));
                }

                $this->pause($sleeps, $attempt, $response->header('retry-after'));

                continue;
            }

            if ($status >= 400) {
                throw new MailRemoteException(sprintf('KI-Anbieter lehnt Anfrage ab (HTTP %d).', $status), self::INTEGRATION, $status, $this->excerpt($response->body()));
            }

            return $response;
        }
    }

    /**
     * @param  array<int, int>  $sleeps
     */
    private function pause(array $sleeps, int $attempt, ?string $retryAfter = null): void
    {
        $ms = $sleeps[$attempt - 1] ?? ($sleeps === [] ? 500 : end($sleeps));

        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $ms = min(10_000, (int) $retryAfter * 1000);
        }

        if ($ms > 0 && ! app()->runningUnitTests()) {
            usleep($ms * 1000);
        }
    }

    /**
     * Messages API: content[] mit type tool_use und input für erzwungene Werkzeugaufrufe. stop_reason refusal
     * oder max_tokens gilt als Fehler, nie als Ergebnis (aus Snippets, am Original zu prüfen).
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function extractToolInput(array $json, Response $response): array
    {
        $stopReason = (string) ($json['stop_reason'] ?? '');

        if ($stopReason === 'refusal') {
            throw new MailRemoteException('KI-Anbieter hat die Anfrage abgelehnt (refusal).', self::INTEGRATION, $response->status(), $this->excerpt($response->body()));
        }

        if ($stopReason === 'max_tokens') {
            throw new MailRemoteException('KI-Antwort abgeschnitten (stop_reason max_tokens).', self::INTEGRATION, $response->status());
        }

        foreach ((array) ($json['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL_NAME && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new MailRemoteException('KI-Antwort enthält keinen Werkzeugaufruf mit Ergebnis.', self::INTEGRATION, $response->status(), $this->excerpt($response->body()));
    }

    private function apiKey(): ?string
    {
        $key = $this->config->get('hub.ai.anthropic.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Auszug ohne Secrets: API-Schlüssel und lange Zeichenfolgen werden entfernt.
     */
    private function excerpt(string $body): string
    {
        $clean = preg_replace('/(sk-ant-[A-Za-z0-9_\-]{8,}|x-api-key\S*)/', '[MASKIERT]', $body) ?? '';

        return mb_substr($clean, 0, 300);
    }
}
