<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

use App\Modules\Connector\Enums\ConnectorType;

/**
 * Laufzeitkontext eines Adapters für genau eine ImmowareConnection. Enthält die Zugangsdaten
 * nur im Speicher; das Objekt darf nicht in Queue-Payloads oder Logs gelangen.
 */
final readonly class ConnectorContext
{
    public function __construct(
        public int $connectionId,
        public int $organizationId,
        public ConnectorType $type,
        public string $connectorType,
        public ?string $baseUrl,
        public ConnectorCredentials $credentials,
        public string $authScheme = 'unknown',
        public string $purpose = 'read',
        public ?int $technicalUserId = null,
        public float $rateLimitRps = 2.0,
        public int $maxConcurrency = 2,
        /** Schreibpräfix der Connection (allowed_write_prefix); nur für PUT einer Schreib-Connection relevant. */
        public ?string $allowedWritePrefix = null,
    ) {}

    /**
     * Pfad der Freigabe-URL (base_url), normalisiert ohne abschließenden Schrägstrich.
     */
    public function basePath(): string
    {
        if ($this->baseUrl === null) {
            return '';
        }

        $path = parse_url($this->baseUrl, PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    public function host(): ?string
    {
        if ($this->baseUrl === null) {
            return null;
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    /**
     * @return array<string, mixed> Loggbare Darstellung ohne Secrets.
     */
    public function toLogContext(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'organization_id' => $this->organizationId,
            'connector' => $this->type->value,
            'connector_type' => $this->connectorType,
            'host' => $this->host(),
            'auth_scheme' => $this->authScheme,
            'purpose' => $this->purpose,
            'allowed_write_prefix' => $this->allowedWritePrefix,
        ];
    }
}
