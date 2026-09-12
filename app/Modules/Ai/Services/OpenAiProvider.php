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
use JsonException;

/**
 * OpenAI-Adapter über die Http-Facade. Responses API mit Structured Outputs (text.format json_schema strict) oder
 * Chat Completions (response_format json_schema) je config hub.ai.api_mode. Kein tools-Feld: die Ausgabe hat keinen
 * Werkzeugzugriff. Alle Feldnamen stammen aus Snippets und sind vor Inbetriebnahme am Original zu prüfen.
 * Kein Live-Test möglich, geprüft nur mit Http::fake().
 *
 * Die Klasse ist reiner Transport: Maskierung, Budget, Schema- und Fachprüfung sowie Protokoll erledigt
 * AiSuggestionService. Der Modellname kommt ausschließlich aus MAIL_AI_MODEL; ohne Modell oder API-Key gilt
 * "Nicht eingerichtet".
 */
final class OpenAiProvider implements AiProviderInterface
{
    public const string INTEGRATION = 'ai';

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
        $model = $this->config->get('hub.ai.model');

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

        $mode = strtolower((string) $this->config->get('hub.ai.api_mode', 'responses')) === 'chat' ? 'chat' : 'responses';
        $system = $this->prompts->systemInstruction($aiTask);
        $user = $this->prompts->userMessage($aiTask, $input);
        $started = hrtime(true);

        $response = $this->send($mode, $apiKey, $this->payload($mode, $model, $task, $system, $user, $schema));
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        $json = $response->json();

        if (! is_array($json)) {
            throw new MailRemoteException('KI-Antwort ist kein JSON.', self::INTEGRATION, $response->status(), $this->excerpt($response->body()));
        }

        $text = $mode === 'chat' ? $this->extractChatText($json, $response) : $this->extractResponsesText($json, $response);

        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MailRemoteException('KI-Antwort enthält kein gültiges JSON-Objekt.', self::INTEGRATION, $response->status(), $this->excerpt($text));
        }

        if (! is_array($decoded)) {
            throw new MailRemoteException('KI-Antwort ist kein JSON-Objekt.', self::INTEGRATION, $response->status(), $this->excerpt($text));
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $decoded['meta'] = [
            'provider' => 'openai',
            'model' => (string) ($json['model'] ?? $model),
            'api_mode' => $mode,
            'input_tokens' => (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
            'latency_ms' => $latency,
            'response_id' => isset($json['id']) ? (string) $json['id'] : null,
        ];

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function payload(string $mode, string $model, string $task, string $system, string $user, array $schema): array
    {
        $name = 'mail_'.preg_replace('/[^a-z0-9_]/', '_', $task);
        $maxOutput = (int) $this->config->get('hub.ai.max_output_tokens', 2000);
        $temperature = $this->config->get('hub.ai.temperature', 0);

        if ($mode === 'chat') {
            // Chat Completions: response_format json_schema (aus Snippets, am Original zu prüfen).
            return [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => $name, 'schema' => $schema, 'strict' => true],
                ],
                'temperature' => $temperature,
                'max_completion_tokens' => $maxOutput,
                'store' => (bool) $this->config->get('hub.ai.store', false),
            ];
        }

        // Responses API: text.format json_schema strict (aus Snippets, am Original zu prüfen).
        return [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'text' => [
                'format' => ['type' => 'json_schema', 'name' => $name, 'schema' => $schema, 'strict' => true],
            ],
            'temperature' => $temperature,
            'max_output_tokens' => $maxOutput,
            'store' => (bool) $this->config->get('hub.ai.store', false),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $mode, string $apiKey, array $payload): Response
    {
        $base = rtrim((string) $this->config->get('hub.ai.base_url', 'https://api.openai.com/v1'), '/');
        $endpoint = (string) $this->config->get('hub.ai.endpoints.'.$mode, $mode === 'chat' ? '/chat/completions' : '/responses');
        $times = max(0, (int) $this->config->get('hub.ai.retry.times', 2));
        $sleeps = array_values(array_map('intval', (array) $this->config->get('hub.ai.retry.sleep_ms', [500, 2000])));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout((int) $this->config->get('hub.ai.connect_timeout_seconds', 10))
                    ->timeout((int) $this->config->get('hub.ai.timeout_seconds', 60))
                    ->post($base.$endpoint, $payload);
            } catch (ConnectionException $e) {
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

                $this->pause($sleeps, $attempt, $response->header('Retry-After'));

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
     * Responses API: output[] mit type message, content[] mit type output_text (aus Snippets). Ein Eintrag type refusal
     * oder status incomplete wird als Fehler behandelt, nie als Ergebnis.
     *
     * @param  array<string, mixed>  $json
     */
    private function extractResponsesText(array $json, Response $response): string
    {
        if (($json['status'] ?? 'completed') !== 'completed') {
            throw new MailRemoteException(sprintf('KI-Antwort unvollständig (Status %s).', (string) $json['status']), self::INTEGRATION, $response->status(), $this->excerpt($response->body()));
        }

        foreach ((array) ($json['output'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }

                if (($content['type'] ?? null) === 'refusal') {
                    throw new MailRemoteException('KI-Anbieter hat die Anfrage abgelehnt (refusal).', self::INTEGRATION, $response->status(), $this->excerpt((string) ($content['refusal'] ?? '')));
                }

                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        throw new MailRemoteException('KI-Antwort enthält keinen Text.', self::INTEGRATION, $response->status(), $this->excerpt($response->body()));
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractChatText(array $json, Response $response): string
    {
        $choice = (array) (((array) ($json['choices'] ?? []))[0] ?? []);
        $message = (array) ($choice['message'] ?? []);

        if (isset($message['refusal']) && is_string($message['refusal']) && $message['refusal'] !== '') {
            throw new MailRemoteException('KI-Anbieter hat die Anfrage abgelehnt (refusal).', self::INTEGRATION, $response->status(), $this->excerpt($message['refusal']));
        }

        if (($choice['finish_reason'] ?? 'stop') === 'length') {
            throw new MailRemoteException('KI-Antwort abgeschnitten (finish_reason length).', self::INTEGRATION, $response->status());
        }

        if (! is_string($message['content'] ?? null)) {
            throw new MailRemoteException('KI-Antwort enthält keinen Text.', self::INTEGRATION, $response->status(), $this->excerpt($response->body()));
        }

        return $message['content'];
    }

    private function apiKey(): ?string
    {
        $key = $this->config->get('hub.ai.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Auszug ohne Secrets: Bearer-Token und lange Schlüssel werden entfernt.
     */
    private function excerpt(string $body): string
    {
        $clean = preg_replace('/(sk-[A-Za-z0-9_\-]{8,}|Bearer\s+\S+)/', '[MASKIERT]', $body) ?? '';

        return mb_substr($clean, 0, 300);
    }
}
