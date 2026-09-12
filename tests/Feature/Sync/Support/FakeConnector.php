<?php

declare(strict_types=1);

namespace Tests\Feature\Sync\Support;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\DTO\ConnectionResult;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Exceptions\WriteBlockedException;
use Throwable;

/**
 * Fake-Adapter für Sync-Tests: liefert vorbereitete Ergebnisse je Cursor und protokolliert Anfragen.
 */
final class FakeConnector implements ImmowareConnectorInterface
{
    /** @var array<int, SyncRequest> */
    public array $requests = [];

    /** @var array<string, SyncResult> Schlüssel: Cursor oder '' für den Start */
    private array $pages = [];

    private ?Throwable $throws = null;

    private ?SyncResult $default = null;

    public function page(?string $cursor, SyncResult $result): self
    {
        $this->pages[$cursor ?? ''] = $result;

        return $this;
    }

    public function always(SyncResult $result): self
    {
        $this->default = $result;

        return $this;
    }

    public function failWith(Throwable $exception): self
    {
        $this->throws = $exception;

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function authenticate(): bool
    {
        return true;
    }

    public function testConnection(): ConnectionResult
    {
        return new ConnectionResult(true);
    }

    public function capabilities(): array
    {
        return ['documents.read', 'contacts.read', 'calendar.read'];
    }

    public function pull(SyncRequest $request): SyncResult
    {
        $this->requests[] = $request;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->pages[$request->cursor ?? ''] ?? $this->default ?? new SyncResult(processed: 1, created: 1);
    }

    public function push(SyncRequest $request): SyncResult
    {
        throw new WriteBlockedException('Fake-Adapter schreibt nie.', 'fake');
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): ?SyncRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }
}
