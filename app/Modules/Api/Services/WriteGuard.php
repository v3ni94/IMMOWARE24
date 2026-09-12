<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Core\Contracts\CapabilityRegistryInterface;
use App\Core\Support\WritePrefixGuard;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Vorprüfungen für schreibende API-Endpunkte: Feature-Flags, Connection-Status und Capability.
 * Hub-eigene Ressourcen (Vorgänge, Änderungsvorschläge) brauchen keine Immoware-Capability.
 */
final class WriteGuard
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container,
    ) {}

    /**
     * Prüft den einzigen Schreibpfad Richtung Immoware24 (create-only PUT in den Posteingang).
     */
    public function assertUploadAllowed(?int $connectionId, int $organizationId): ImmowareConnection
    {
        if (! (bool) $this->config->get('hub.core.write.enabled', false) || ! (bool) $this->config->get('hub.core.write.webdav_create_enabled', false)) {
            throw ApiProblemException::forbidden('write_disabled', 'Der Schreibpfad nach Immoware24 ist deaktiviert (IMMOWARE_WRITE_ENABLED).');
        }

        $query = ImmowareConnection::query()->withoutGlobalScopes()->where('organization_id', $organizationId);

        $connection = $connectionId !== null
            ? $query->whereKey($connectionId)->first()
            : $query->where('purpose', 'write')->where('write_enabled', true)->orderBy('id')->first();

        if (! $connection instanceof ImmowareConnection) {
            throw ApiProblemException::forbidden('write_disabled', 'Keine freigegebene Schreib-Connection vorhanden.');
        }

        if (! (bool) $connection->getAttribute('write_enabled')) {
            throw ApiProblemException::forbidden('write_disabled', 'Die Connection ist nicht für Schreibvorgänge freigegeben.');
        }

        // Vier-Augen-Prinzip (05 2.2 Nr. 2): write_enabled allein genügt nicht.
        if (! $connection->hasCompleteWriteApproval()) {
            throw ApiProblemException::forbidden('write_approval_incomplete', 'Die Schreibfreigabe der Connection ist unvollständig (Vier-Augen-Prinzip, Freigabedokument).');
        }

        if ((string) $connection->getAttribute('status') !== 'active') {
            throw ApiProblemException::forbidden('connection_degraded', 'Die Connection ist nicht aktiv.');
        }

        // Einziger Schreibpfad ist WebDAV mit purpose write (05 2.2 Nr. 1); CardDAV, CalDAV, Dateiimport und der
        // REST-Slot schreiben nie, unabhängig von write_enabled.
        try {
            $type = ConnectorType::fromConnectorType((string) $connection->getAttribute('connector_type'));
        } catch (InvalidArgumentException) {
            $type = null;
        }

        if ($type !== ConnectorType::WebDav || (string) $connection->getAttribute('purpose') !== 'write') {
            throw ApiProblemException::forbidden('write_disabled', 'Nur WebDAV-Connections mit Zweck write dürfen in den Posteingang schreiben.');
        }

        // Ohne gültigen Schreibpräfix (führender und abschließender Schrägstrich, nicht die Wurzel, nicht /Dokumente/)
        // ist kein Upload möglich; die Zielprüfung im WebDavClient braucht diesen Prefix.
        $prefix = $connection->getAttribute('allowed_write_prefix');

        if (! is_string($prefix) || ! WritePrefixGuard::allows($prefix)) {
            throw ApiProblemException::forbidden('write_prefix_invalid', 'Der erlaubte Schreibpfad der Connection fehlt oder ist ungültig (allowed_write_prefix).');
        }

        if ($this->container->bound(CapabilityRegistryInterface::class)) {
            $registry = $this->container->make(CapabilityRegistryInterface::class);
            $registry->refresh((int) $connection->getKey());

            if (! $registry->has('documents.write')) {
                throw ApiProblemException::forbidden('capability_locked', 'Die Fähigkeit documents.write ist für diese Connection nicht freigegeben.');
            }
        }

        return $connection;
    }
}
