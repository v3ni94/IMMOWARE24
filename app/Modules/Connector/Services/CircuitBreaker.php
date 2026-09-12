<?php

declare(strict_types=1);

namespace App\Modules\Connector\Services;

use App\Core\Exceptions\CircuitOpenException;
use App\Modules\Connector\Enums\CircuitState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker je Connection und Kanal im Cache (docs/immoware/07-sync-strategy.md Abschnitt 6.2).
 * closed: normal. open: keine Requests bis open_until. half_open: genau ein Testrequest.
 */
final class CircuitBreaker
{
    private const string PREFIX = 'hub:breaker:';

    /**
     * @param  array<string, mixed>  $config  Inhalt von config('hub.connector.circuit_breaker')
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly array $config,
    ) {}

    public static function keyFor(int $connectionId, string $channel = 'read'): string
    {
        return sprintf('conn:%d:%s', $connectionId, strtolower($channel));
    }

    public function state(string $key): CircuitState
    {
        $data = $this->load($key);

        if ($data['state'] === CircuitState::Open->value && $data['open_until'] !== null && $this->now() >= $data['open_until']) {
            return CircuitState::HalfOpen;
        }

        return CircuitState::from($data['state']);
    }

    /**
     * Prüft vor einem Request. Im Zustand half_open darf genau ein Testrequest passieren.
     *
     * @throws CircuitOpenException
     */
    public function assertAvailable(string $key): void
    {
        $data = $this->load($key);
        $state = $this->state($key);

        if ($state === CircuitState::Closed) {
            return;
        }

        if ($state === CircuitState::HalfOpen && ! $data['trial_in_flight']) {
            $data['state'] = CircuitState::HalfOpen->value;
            $data['trial_in_flight'] = true;
            $this->store($key, $data);

            return;
        }

        $retryIn = $data['open_until'] !== null ? max(0, $data['open_until'] - $this->now()) : null;

        throw new CircuitOpenException(sprintf(
            'Circuit Breaker für "%s" ist %s%s.',
            $key,
            $state === CircuitState::HalfOpen ? 'halb offen (Testrequest läuft)' : 'offen',
            $retryIn !== null ? sprintf(', nächster Versuch in %d s', $retryIn) : '',
        ), $key);
    }

    public function recordSuccess(string $key): void
    {
        $data = $this->load($key);

        if ($data['state'] !== CircuitState::Closed->value) {
            Log::info('Circuit Breaker geschlossen.', ['circuit' => $key]);
        }

        $this->store($key, [
            'state' => CircuitState::Closed->value,
            'failures' => [],
            'open_until' => null,
            'open_cycles' => 0,
            'trial_in_flight' => false,
        ]);
    }

    /**
     * @param  bool  $immediate  true bei 401: sofortiges Öffnen unabhängig vom Schwellwert
     */
    public function recordFailure(string $key, bool $immediate = false, ?string $reason = null): void
    {
        $data = $this->load($key);
        $now = $this->now();
        $window = (int) ($this->config['failure_window_seconds'] ?? 120);
        $threshold = (int) ($this->config['failure_threshold'] ?? 5);

        $data['failures'] = array_values(array_filter($data['failures'], static fn (int $ts): bool => $ts > $now - $window));
        $data['failures'][] = $now;

        $wasHalfOpen = $this->state($key) === CircuitState::HalfOpen || $data['trial_in_flight'];

        if ($immediate || $wasHalfOpen || count($data['failures']) >= $threshold) {
            $this->open($key, $data, $reason ?? ($immediate ? 'immediate' : 'threshold'));

            return;
        }

        $this->store($key, $data);
    }

    /**
     * Antwort auf HTTP-Statuscode auswerten: 401 öffnet sofort, 403 zählt nicht, 429/5xx zählen.
     */
    public function recordStatus(string $key, ?int $status, bool $timedOut = false, bool $retryAfterPresent = false): void
    {
        if ($timedOut) {
            $this->recordFailure($key, false, 'timeout');

            return;
        }

        if ($status === null) {
            $this->recordFailure($key, false, 'connection_failed');

            return;
        }

        if ($status === 401) {
            $this->recordFailure($key, true, '401');

            return;
        }

        if ($status === 429 && ! $retryAfterPresent) {
            $this->recordFailure($key, false, '429');

            return;
        }

        if ($status >= 500) {
            $this->recordFailure($key, false, (string) $status);

            return;
        }

        if ($status < 400 || $status === 403 || $status === 404 || $status === 412) {
            // 403 und 404 sind fachliche Signale, kein Breaker-Fehler.
            $data = $this->load($key);

            if ($data['trial_in_flight'] || $data['state'] !== CircuitState::Closed->value) {
                if ($status < 400) {
                    $this->recordSuccess($key);
                } else {
                    $data['trial_in_flight'] = false;
                    $this->store($key, $data);
                }
            }
        }
    }

    public function reset(string $key): void
    {
        $this->cache->forget(self::PREFIX.$key);
    }

    /**
     * @return array{state: string, failures: array<int, int>, open_until: int|null, open_cycles: int, trial_in_flight: bool}
     */
    public function snapshot(string $key): array
    {
        $data = $this->load($key);
        $data['state'] = $this->state($key)->value;

        return $data;
    }

    /**
     * @param  array{state: string, failures: array<int, int>, open_until: int|null, open_cycles: int, trial_in_flight: bool}  $data
     */
    private function open(string $key, array $data, string $reason): void
    {
        $cycles = $data['open_cycles'] + 1;
        $extendedAfter = (int) ($this->config['extended_after_cycles'] ?? 3);
        $seconds = $cycles >= $extendedAfter
            ? (int) ($this->config['extended_open_seconds'] ?? 3600)
            : (int) ($this->config['open_seconds'] ?? 600);

        $data['state'] = CircuitState::Open->value;
        $data['open_until'] = $this->now() + $seconds;
        $data['open_cycles'] = $cycles;
        $data['trial_in_flight'] = false;
        $data['failures'] = [];

        $this->store($key, $data);

        Log::warning('Circuit Breaker geöffnet.', [
            'circuit' => $key,
            'reason' => $reason,
            'open_seconds' => $seconds,
            'cycle' => $cycles,
        ]);
    }

    /**
     * @return array{state: string, failures: array<int, int>, open_until: int|null, open_cycles: int, trial_in_flight: bool}
     */
    private function load(string $key): array
    {
        /** @var array{state: string, failures: array<int, int>, open_until: int|null, open_cycles: int, trial_in_flight: bool}|null $data */
        $data = $this->cache->get(self::PREFIX.$key);

        return $data ?? [
            'state' => CircuitState::Closed->value,
            'failures' => [],
            'open_until' => null,
            'open_cycles' => 0,
            'trial_in_flight' => false,
        ];
    }

    /**
     * @param  array{state: string, failures: array<int, int>, open_until: int|null, open_cycles: int, trial_in_flight: bool}  $data
     */
    private function store(string $key, array $data): void
    {
        $this->cache->put(self::PREFIX.$key, $data, now()->addDay());
    }

    private function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }
}
