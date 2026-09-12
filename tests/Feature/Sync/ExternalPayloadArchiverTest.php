<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

final class ExternalPayloadArchiverTest extends SyncTestCase
{
    public function test_archive_masks_secrets_and_roundtrips_compressed_inline(): void
    {
        $connection = $this->activeConnection();
        $archiver = $this->app->make(ExternalPayloadArchiver::class);

        $raw = "BEGIN:VCARD\nUID:abc\nX-AUTH:Authorization: Basic aHViOnBhc3N3b3Jk\nURL:https://hub-read:TopSecret99@dav.example.test/x\nEND:VCARD";
        $payload = $archiver->archive((int) $connection->getKey(), 'vcard', $raw, 'abc', null, ['headers' => ['Authorization' => 'Basic xyz', 'ETag' => '"1"']]);

        $this->assertSame(hash('sha256', 'abc'), $payload->getAttribute('external_id_hash'));
        $this->assertNull($payload->getAttribute('storage_key'));
        $this->assertNotNull($payload->getAttribute('dedup_hash'));

        $stored = $archiver->contents($payload);
        $this->assertIsString($stored);
        $this->assertStringContainsString('UID:abc', $stored);
        $this->assertStringNotContainsString('aHViOnBhc3N3b3Jk', $stored);
        $this->assertStringNotContainsString('TopSecret99', $stored);
        $this->assertSame('***', $payload->getAttribute('import_metadata')['headers']['Authorization']);
        $this->assertSame('"1"', $payload->getAttribute('import_metadata')['headers']['ETag']);
        $this->assertSame('gzip', $payload->getAttribute('import_metadata')['_encoding']);
    }

    public function test_large_payloads_go_to_storage_disk(): void
    {
        Storage::fake('local');
        config()->set('hub.sync.payloads.compress', false);
        $connection = $this->activeConnection();
        $archiver = $this->app->make(ExternalPayloadArchiver::class);

        $raw = str_repeat(bin2hex(random_bytes(32)), 2048); // 128 KB, nicht komprimierbar
        $payload = $archiver->archive((int) $connection->getKey(), 'propfind', $raw);

        $this->assertNull($payload->getAttribute('content_inline'));
        $this->assertIsString($payload->getAttribute('storage_key'));
        Storage::disk('local')->assertExists($payload->getAttribute('storage_key'));
        $this->assertSame($raw, $archiver->contents($payload));
    }

    public function test_file_payload_duplicates_are_rejected_by_dedup_hash(): void
    {
        $connection = $this->activeConnection();
        $archiver = $this->app->make(ExternalPayloadArchiver::class);
        $archiver->archive((int) $connection->getKey(), 'csv_file', "a;b\n1;2");

        $this->expectException(QueryException::class);
        $archiver->archive((int) $connection->getKey(), 'csv_file', "a;b\n1;2");
    }

    public function test_prune_removes_expired_payloads_and_keeps_personal_data_until_pseudonymized(): void
    {
        Storage::fake('local');
        $connection = $this->activeConnection();
        $archiver = $this->app->make(ExternalPayloadArchiver::class);
        $connectionId = (int) $connection->getKey();

        $old = $archiver->archive($connectionId, 'vcard', 'alt');
        $oldPersonal = $archiver->archive($connectionId, 'vcard', 'alt personenbezogen', containsPersonalData: true);
        $fresh = $archiver->archive($connectionId, 'vcard', 'neu');

        ExternalPayload::query()->whereIn('id', [$old->getKey(), $oldPersonal->getKey()])->update(['received_at' => CarbonImmutable::now()->subDays(120)]);

        $this->assertSame(1, $archiver->prune(90, true), 'Dry-Run zählt nur.');
        $this->assertSame(3, ExternalPayload::query()->count());

        $this->artisan('hub:payloads:prune', ['--days' => 90])->assertSuccessful();

        $this->assertNull(ExternalPayload::query()->find($old->getKey()));
        $this->assertNotNull(ExternalPayload::query()->find($oldPersonal->getKey()));
        $this->assertNotNull(ExternalPayload::query()->find($fresh->getKey()));
    }
}
