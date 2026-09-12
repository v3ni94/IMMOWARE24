<?php

declare(strict_types=1);

namespace Tests\Unit\Connector;

use App\Core\Support\SecretMasker;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Enums\SyncStrategy;
use App\Modules\Connector\Support\ConnectorCredentials;
use App\Modules\Connector\Support\ResponseSchemaFingerprint;
use App\Modules\Connector\Support\UrlSanitizer;
use PHPUnit\Framework\TestCase;

final class SupportHelpersTest extends TestCase
{
    public function test_url_sanitizer_removes_userinfo_and_masks_secret_query_params(): void
    {
        $sanitizer = new UrlSanitizer(new SecretMasker);

        $result = $sanitizer->sanitize('https://hub:geheim@dav.example.test:8443/share/a b?token=abc123&page=2&password=xyz');

        $this->assertStringNotContainsString('geheim', $result);
        $this->assertStringNotContainsString('abc123', $result);
        $this->assertStringNotContainsString('xyz', $result);
        $this->assertStringContainsString('dav.example.test:8443/share/a b', $result);
        $this->assertStringContainsString('page=2', $result);
    }

    public function test_schema_fingerprint_ignores_values_but_reflects_structure(): void
    {
        $fp = new ResponseSchemaFingerprint;

        $a = $fp->compute('<D:multistatus xmlns:D="DAV:"><D:response><D:href>/a</D:href></D:response></D:multistatus>');
        $b = $fp->compute('<D:multistatus xmlns:D="DAV:"><D:response><D:href>/b/other</D:href></D:response></D:multistatus>');
        $c = $fp->compute('<D:multistatus xmlns:D="DAV:"><D:response><D:href>/a</D:href><D:status>x</D:status></D:response></D:multistatus>');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame($fp->compute('{"a":{"b":1}}'), $fp->compute('{"a":{"b":"zwei"}}'));
        $this->assertNull($fp->compute(null));
        $this->assertNotNull($fp->compute('', 'text/plain'));
    }

    public function test_credentials_are_masked_and_not_serializable(): void
    {
        $credentials = new ConnectorCredentials('hub-read', 'sehr-geheim');

        $this->assertSame('sehr-geheim', $credentials->password());
        $this->assertStringNotContainsString('sehr-geheim', print_r($credentials, true));

        $this->expectException(\LogicException::class);
        serialize($credentials);
    }

    public function test_connector_type_mapping_and_strategy_choice(): void
    {
        $this->assertSame(ConnectorType::WebDav, ConnectorType::fromConnectorType('webdav_documents'));
        $this->assertSame(ConnectorType::CardDav, ConnectorType::fromConnectorType('carddav_contacts'));
        $this->assertSame(ConnectorType::CalDav, ConnectorType::fromConnectorType('caldav_calendar'));
        $this->assertSame(ConnectorType::FileImport, ConnectorType::fromConnectorType('csv_export'));
        $this->assertSame(ConnectorType::RestApiSlot, ConnectorType::fromConnectorType('rest_api_slot'));

        $this->assertSame(SyncStrategy::SyncToken, SyncStrategy::choose(true, true, true, true));
        $this->assertSame(SyncStrategy::CtagEtag, SyncStrategy::choose(false, true, true, true));
        $this->assertSame(SyncStrategy::EtagOnly, SyncStrategy::choose(false, false, true, true));
        $this->assertSame(SyncStrategy::LastModifiedSizeHash, SyncStrategy::choose(false, false, false, true));
        $this->assertSame(SyncStrategy::FullHash, SyncStrategy::choose(false, false, false, false));

        $this->expectException(\InvalidArgumentException::class);
        ConnectorType::fromConnectorType('graphql');
    }
}
