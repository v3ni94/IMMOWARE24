<?php

declare(strict_types=1);

namespace App\Modules\MailUi\DTO;

/**
 * Ergebnis eines Aufrufs an ein Fachmodul aus der Oberfläche. Drei Ausgänge: ok (fachlich erfasst), unavailable
 * (Dienst nicht verdrahtet oder Integration nicht eingerichtet, sichtbar als Hinweis, nie als Erfolg) und failed.
 */
final readonly class WorkflowResult
{
    private function __construct(
        public string $outcome,
        public string $message,
        public ?int $entityId = null,
    ) {}

    public static function ok(string $message, ?int $entityId = null): self
    {
        return new self('ok', $message, $entityId);
    }

    public static function unavailable(string $message): self
    {
        return new self('unavailable', $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }

    public function isOk(): bool
    {
        return $this->outcome === 'ok';
    }

    /**
     * Flash-Schlüssel des Layouts: status (Erfolg), warning (nicht verfügbar), error (Fehler).
     */
    public function flashKey(): string
    {
        return match ($this->outcome) {
            'ok' => 'status',
            'unavailable' => 'warning',
            default => 'error',
        };
    }
}
