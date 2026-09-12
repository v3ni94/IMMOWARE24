<?php

declare(strict_types=1);

namespace App\Modules\Ai\Testing;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Modules\Mail\Exceptions\MailRemoteException;
use RuntimeException;

/**
 * Liefert vorgegebene strukturierte Antworten je Aufgabe (Queue je task, danach Standardantwort). Protokolliert
 * Eingaben, damit Tests Maskierung und Schema prüfen können. Kein Live-Test eines KI-Anbieters.
 */
final class FakeAiProvider implements AiProviderInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $queued = [];

    /** @var array<string, array<string, mixed>> */
    private array $defaults = [];

    /** @var array<int, array{task: string, input: array<string, mixed>, schema: array<string, mixed>}> */
    private array $calls = [];

    private ?MailRemoteException $failure = null;

    /**
     * @param  array<string, mixed>  $response
     */
    public function queue(string $task, array $response): void
    {
        $this->queued[$task][] = $response;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function setDefault(string $task, array $response): void
    {
        $this->defaults[$task] = $response;
    }

    public function failNext(?MailRemoteException $exception = null): void
    {
        $this->failure = $exception ?? new MailRemoteException('Fake: KI-Anbieter nicht erreichbar.', 'ai', 503, null);
    }

    /**
     * @return array<int, array{task: string, input: array<string, mixed>, schema: array<string, mixed>}>
     */
    public function calls(?string $task = null): array
    {
        return $task === null
            ? $this->calls
            : array_values(array_filter($this->calls, static fn (array $call): bool => $call['task'] === $task));
    }

    public function structured(string $task, array $input, array $schema): array
    {
        $this->calls[] = ['task' => $task, 'input' => $input, 'schema' => $schema];

        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }

        if (isset($this->queued[$task]) && $this->queued[$task] !== []) {
            $response = array_shift($this->queued[$task]);
        } elseif (isset($this->defaults[$task])) {
            $response = $this->defaults[$task];
        } else {
            throw new RuntimeException(sprintf('FakeAiProvider: keine Antwort für Aufgabe %s vorgegeben (queue() oder setDefault()).', $task));
        }

        $response['meta'] = ($response['meta'] ?? []) + [
            'provider' => 'fake',
            'model' => 'fake-model',
            'input_tokens' => strlen(json_encode($input, JSON_THROW_ON_ERROR)) >> 2,
            'output_tokens' => strlen(json_encode($response, JSON_THROW_ON_ERROR)) >> 2,
            'latency_ms' => 1,
        ];

        return $response;
    }
}
