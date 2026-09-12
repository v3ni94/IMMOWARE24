<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Core\Enums\CapabilityStatus;
use App\Core\Enums\Role;
use App\Modules\Connector\Models\Capability;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\User;
use Illuminate\Http\Client\Request;

/**
 * Gefälschte WebDAV-Antworten und Connections für die Tests des Moduls Documents.
 */
trait DocumentsTestHelpers
{
    /**
     * @param  array<int, array<string, mixed>>  $entries  Einträge mit href, collection, etag, modified, length, type, displayname
     */
    protected function multistatus(string $basePath, array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">';

        foreach ($entries as $entry) {
            $href = $basePath.(string) $entry['href'];
            $xml .= '<D:response><D:href>'.htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</D:href><D:propstat><D:prop>';
            $xml .= ($entry['collection'] ?? false) ? '<D:resourcetype><D:collection/></D:resourcetype>' : '<D:resourcetype/>';

            if (isset($entry['etag'])) {
                $xml .= '<D:getetag>"'.$entry['etag'].'"</D:getetag>';
            }

            if (isset($entry['modified'])) {
                $xml .= '<D:getlastmodified>'.$entry['modified'].'</D:getlastmodified>';
            }

            if (isset($entry['length'])) {
                $xml .= '<D:getcontentlength>'.$entry['length'].'</D:getcontentlength>';
            }

            if (isset($entry['type'])) {
                $xml .= '<D:getcontenttype>'.$entry['type'].'</D:getcontenttype>';
            }

            if (isset($entry['displayname'])) {
                $xml .= '<D:displayname>'.htmlspecialchars((string) $entry['displayname'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</D:displayname>';
            }

            $xml .= '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }

        return $xml.'</D:multistatus>';
    }

    protected function basePathOf(ImmowareConnection $connection): string
    {
        $path = parse_url((string) $connection->getAttribute('base_url'), PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    protected function requestPath(Request $request): string
    {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return rawurldecode(is_string($path) ? $path : '/');
    }

    /**
     * Pfad relativ zur Freigabe, dekodiert.
     */
    protected function relativePath(Request $request, ImmowareConnection $connection): string
    {
        $path = $this->requestPath($request);
        $base = $this->basePathOf($connection);

        return $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    protected function readConnection(?Organization $organization = null): ImmowareConnection
    {
        return $this->createConnection($organization, ['rate_limit_rps' => 50, 'status' => 'active', 'last_health_ok' => true]);
    }

    /**
     * Schreib-Connection mit allen Freigaben gemäß 05-write-capabilities.md Abschnitt 2.2.
     */
    protected function writeConnection(?ImmowareConnection $paired = null): ImmowareConnection
    {
        $paired ??= $this->readConnection();

        $requester = User::factory()->role(Role::Administrator)->for($paired->organization)->create();
        $confirmer = User::factory()->role(Role::Owner)->for($paired->organization)->create();

        $connection = $this->createConnection($paired->organization, [
            'name' => 'WebDAV Schreib-Connection',
            'purpose' => 'write',
            'write_enabled' => true,
            // Vier-Augen-Prinzip: Beantragung durch admin, Bestätigung durch Owner (release), Freigabedokument hinterlegt.
            'write_enabled_by' => $requester->getKey(),
            'write_confirmed_by' => $confirmer->getKey(),
            'write_approval_document_id' => 1,
            'write_enabled_at' => now(),
            'status' => 'active',
            'allowed_write_prefix' => '/Posteingang/',
            'paired_read_connection_id' => $paired->getKey(),
            'rate_limit_rps' => 50,
            'credentials' => ['username' => 'hub-write', 'password' => 'write-secret'],
        ]);

        Capability::factory()->for($connection, 'connection')->key('documents.write')->status(CapabilityStatus::Tested)->create(['enabled' => true]);

        return $connection;
    }

    protected function enableWriteFlags(): void
    {
        config()->set('hub.core.write.enabled', true);
        config()->set('hub.core.write.webdav_create_enabled', true);
        config()->set('hub.core.write.dry_run', false);
    }
}
