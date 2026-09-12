<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Core\Enums\WriteOperationStatus;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\DTO\UploadRequest;
use App\Modules\Documents\DTO\UploadResult;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\PosteingangUploadService;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Models\WriteOperation;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PosteingangUploadTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    /** @var array<string, string> Pfad => Inhalt der "entfernten" Dateien */
    private array $remote = [];

    private bool $putTimesOut = false;

    private ?int $putStatus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWriteFlags();
    }

    public function test_successful_upload_is_prechecked_put_once_and_verified_with_hash(): void
    {
        $connection = $this->writeConnection();
        $this->fakeServer($connection);
        $service = $this->service();

        $result = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'Rechnung Müller & Söhne.pdf', 'intent-1', 'application/pdf', null, 'ui'));

        $this->assertSame(UploadResult::OUTCOME_UPLOADED, $result->outcome);
        $this->assertSame(WriteOperationStatus::Verified, $result->status());

        $operation = $result->operation->fresh();
        $this->assertNotNull($operation);
        $this->assertSame(1, $operation->getAttribute('put_attempts'));
        $this->assertSame(201, $operation->getAttribute('http_status'));
        $this->assertNotNull($operation->getAttribute('sent_at'));
        $this->assertNotNull($operation->getAttribute('verified_at'));
        $this->assertTrue($operation->getAttribute('verify_result')['hash_match']);
        $this->assertStringStartsWith('/Posteingang/Rechnung_Mueller_Soehne_', (string) $operation->getAttribute('target_path'));
        $this->assertStringEndsWith('.pdf', (string) $operation->getAttribute('target_path'));
        $this->assertSame(hash('sha256', 'inhalt'), $operation->getAttribute('content_hash'));
        $this->assertNotNull($operation->getAttribute('operation_uuid'));
        $this->assertSame(WriteOperation::idempotencyKey((int) $connection->getKey(), hash('sha256', 'inhalt'), hash('sha256', 'intent-1')), $operation->getAttribute('idempotency_key'));

        Http::assertSentCount(4); // PROPFIND Precheck, PUT, PROPFIND Verify, GET Hash
        $this->assertSame(1, $this->countSent('PUT'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && ($r->header('If-None-Match')[0] ?? null) === '*');

        // Dokumentzeile auf der gekoppelten Lese-Connection mit origin hub_upload.
        $document = Document::query()->where('write_operation_id', $operation->getKey())->firstOrFail();
        $this->assertSame((int) $connection->getAttribute('paired_read_connection_id'), $document->getAttribute('connection_id'));
        $this->assertSame('hub_upload', $document->getAttribute('origin'));
        $this->assertFalse((bool) $document->getAttribute('content_stored'));

        $actions = AuditLog::query()->where('entity_type', 'WriteOperation')->pluck('action')->all();
        $this->assertContains('write.queued', $actions);
        $this->assertContains('write.sent', $actions);
        $this->assertContains('write.verified', $actions);
    }

    public function test_second_call_with_same_payload_is_idempotent_and_sends_no_second_put(): void
    {
        $connection = $this->writeConnection();
        $this->fakeServer($connection);
        $service = $this->service();

        $first = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-1'));
        $this->assertSame(1, $this->countSent('PUT'));

        $second = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-1'));

        $this->assertSame(UploadResult::OUTCOME_IDEMPOTENT_REPLAY, $second->outcome);
        $this->assertSame($first->operation->getKey(), $second->operation->getKey());
        $this->assertSame(1, WriteOperation::query()->count());
        $this->assertSame(1, $this->countSent('PUT'));

        // process() auf eine verifizierte Operation löst ebenfalls kein PUT aus.
        $replay = $service->process($first->operation->fresh() ?? $first->operation, 'inhalt');
        $this->assertSame(UploadResult::OUTCOME_IDEMPOTENT_REPLAY, $replay->outcome);
        $this->assertSame(1, $this->countSent('PUT'));
    }

    public function test_timeout_leads_to_unknown_and_is_resolved_only_via_propfind_without_second_put(): void
    {
        $connection = $this->writeConnection();
        $this->putTimesOut = true;
        $this->fakeServer($connection);
        $service = $this->service();

        $result = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-2'));

        $this->assertSame(UploadResult::OUTCOME_UNKNOWN, $result->outcome);
        $this->assertSame(WriteOperationStatus::Unknown, $result->status());
        $this->assertSame(1, $result->operation->getAttribute('put_attempts'));
        $this->assertSame(1, $this->countSent('PUT'));
        $this->assertStringNotContainsString('write-secret', (string) $result->operation->getAttribute('last_error'));

        // Retry über upload() mit gleicher Nutzlast: kein zweites PUT.
        $retry = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-2'));
        $this->assertSame(UploadResult::OUTCOME_IDEMPOTENT_REPLAY, $retry->outcome);
        $this->assertSame(1, $this->countSent('PUT'));

        // Erste PROPFIND-Prüfung: Datei noch nicht sichtbar, bleibt unknown.
        $operation = $result->operation->fresh() ?? $result->operation;
        $first = $service->resolveUnknown($operation);
        $this->assertSame(UploadResult::OUTCOME_UNKNOWN, $first->outcome);
        $this->assertSame(WriteOperationStatus::Unknown, $first->status());

        // Datei taucht auf (PUT kam an): PROPFIND findet sie, Verifikation per GET, verified.
        $this->remote[(string) $operation->getAttribute('target_path')] = 'inhalt';
        $second = $service->resolveUnknown($operation->fresh() ?? $operation);

        $this->assertSame(UploadResult::OUTCOME_UPLOADED, $second->outcome);
        $this->assertSame(WriteOperationStatus::Verified, $second->status());
        $this->assertSame(1, $this->countSent('PUT'), 'nie ein zweites PUT');
        $this->assertSame(1, $second->operation->getAttribute('put_attempts'));
    }

    public function test_unknown_becomes_failed_after_configured_propfind_attempts_and_never_puts_again(): void
    {
        config()->set('hub.core.write.unknown_propfind_attempts', 3);
        $connection = $this->writeConnection();
        $this->putTimesOut = true;
        $this->fakeServer($connection);
        $service = $this->service();

        $result = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-3'));
        $this->assertSame(WriteOperationStatus::Unknown, $result->status());

        $operation = $result->operation;

        for ($i = 1; $i <= 2; $i++) {
            $step = $service->resolveUnknown($operation->fresh() ?? $operation);
            $this->assertSame(WriteOperationStatus::Unknown, $step->status(), 'Versuch '.$i);
        }

        $final = $service->resolveUnknown($operation->fresh() ?? $operation);
        $this->assertSame(WriteOperationStatus::Failed, $final->status());
        $this->assertSame('write_unknown_unresolved', $final->operation->getAttribute('verify_result')['conflict']);
        $this->assertSame(1, $this->countSent('PUT'));
        $this->assertSame(4, $this->countSent('PROPFIND'), 'Precheck plus drei Auflösungsversuche');

        // process() auf failed: kein weiterer Versuch, kein PUT.
        $again = $service->process($final->operation, 'inhalt');
        $this->assertSame(UploadResult::OUTCOME_IDEMPOTENT_REPLAY, $again->outcome);
        $this->assertSame(1, $this->countSent('PUT'));
    }

    public function test_412_is_rejected_and_precheck_hit_with_identical_content_is_verified_without_put(): void
    {
        $connection = $this->writeConnection();
        $this->putStatus = 412;
        $this->fakeServer($connection);
        $service = $this->service();

        $rejected = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-412'));
        $this->assertSame(UploadResult::OUTCOME_REJECTED, $rejected->outcome);
        $this->assertSame(WriteOperationStatus::Failed, $rejected->status(), 'nach sent ist nur failed zulässig (kein Rücksprung)');
        $this->assertSame(412, $rejected->operation->getAttribute('http_status'));
        $this->assertSame(1, $rejected->operation->getAttribute('put_attempts'));
        $this->assertSame('write_target_exists', $rejected->operation->getAttribute('precheck_result')['conflict']);
        $this->assertSame(1, $this->countSent('PUT'));

        // Precheck findet das Ziel bereits mit identischem Inhalt: verified ohne PUT.
        $this->putStatus = null;
        $service2 = $this->service();
        $identicalRequest = new UploadRequest((int) $connection->getKey(), 'gleich', 'b.pdf', 'intent-identisch');

        // Zielpfad ist erst nach Anlage bekannt; deshalb über einen Hook: alle PROPFIND auf /Posteingang/b_* finden die Datei.
        $this->remote['*b_'] = 'gleich';
        $identical = $service2->upload($identicalRequest);

        $this->assertSame(UploadResult::OUTCOME_EXISTS_IDENTICAL, $identical->outcome);
        $this->assertSame(WriteOperationStatus::Verified, $identical->status());
        $this->assertTrue($identical->operation->getAttribute('precheck_result')['exists_identical']);
        $this->assertSame(1, $this->countSent('PUT'), 'kein PUT bei identischem Bestand');

        // Precheck findet abweichenden Inhalt: rejected.
        $this->remote['*c_'] = 'anders';
        $different = $service2->upload(new UploadRequest((int) $connection->getKey(), 'gleich', 'c.pdf', 'intent-anders'));
        $this->assertSame(UploadResult::OUTCOME_REJECTED, $different->outcome);
        $this->assertSame('write_target_exists', $different->operation->getAttribute('precheck_result')['conflict']);
        $this->assertSame(1, $this->countSent('PUT'));
    }

    public function test_path_outside_prefix_and_oversize_are_rejected_without_network(): void
    {
        $connection = $this->writeConnection();
        $connection->forceFill(['allowed_write_prefix' => '/'])->save();
        $this->fakeServer($connection);
        $service = $this->service();

        $result = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', '../../Dokumente/x.pdf', 'intent-prefix'));
        $this->assertSame(UploadResult::OUTCOME_REJECTED, $result->outcome);
        $this->assertSame(WriteOperationStatus::Rejected, $result->status());
        $this->assertSame('allowed_prefix_missing', $result->operation->getAttribute('precheck_result')['rejected_reason']);
        Http::assertNothingSent();

        $connection->forceFill(['allowed_write_prefix' => '/Posteingang/', 'max_upload_bytes' => 4])->save();
        $tooLarge = $service->upload(new UploadRequest((int) $connection->getKey(), 'zu gross', 'x.pdf', 'intent-size'));
        $this->assertSame('max_upload_bytes_exceeded', $tooLarge->operation->getAttribute('precheck_result')['rejected_reason']);
        Http::assertNothingSent();

        // Zielpfad wird immer aus Präfix plus bereinigtem Dateinamen gebildet: Traversal landet im Posteingang.
        $ok = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', '../../Dokumente/x.pdf', 'intent-ok'));
        $this->assertStringStartsWith('/Posteingang/x_', (string) $ok->operation->getAttribute('target_path'));
        $this->assertStringNotContainsString('..', (string) $ok->operation->getAttribute('target_path'));
    }

    public function test_write_flags_off_or_capability_missing_keep_request_pending_without_network(): void
    {
        $connection = $this->writeConnection();
        $this->fakeServer($connection);
        $service = $this->service();

        config()->set('hub.core.write.enabled', false);
        $denied = $service->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-off'));
        $this->assertSame(UploadResult::OUTCOME_DENIED, $denied->outcome);
        $this->assertSame(WriteOperationStatus::Pending, $denied->status());
        $this->assertSame('write_disabled_global', $denied->operation->getAttribute('precheck_result')['denied_reason']);
        $this->assertSame(1, AuditLog::query()->where('action', 'write.denied_global')->count());

        config()->set('hub.core.write.enabled', true);
        $read = ImmowareConnection::query()->findOrFail($connection->getAttribute('paired_read_connection_id'));
        $onRead = $service->upload(new UploadRequest((int) $read->getKey(), 'inhalt', 'a.pdf', 'intent-read'));
        $this->assertSame(UploadResult::OUTCOME_DENIED, $onRead->outcome);
        $this->assertSame('capability_documents_write_missing', $onRead->operation->getAttribute('precheck_result')['denied_reason']);

        Http::assertNothingSent();
    }

    public function test_dry_run_performs_precheck_but_no_put(): void
    {
        config()->set('hub.core.write.dry_run', true);
        $connection = $this->writeConnection();
        $this->fakeServer($connection);

        $result = $this->service()->upload(new UploadRequest((int) $connection->getKey(), 'inhalt', 'a.pdf', 'intent-dry'));

        $this->assertSame(UploadResult::OUTCOME_DRY_RUN, $result->outcome);
        $this->assertSame(WriteOperationStatus::Prechecked, $result->status());
        $this->assertTrue($result->operation->getAttribute('precheck_result')['dry_run']);
        $this->assertSame(0, $result->operation->getAttribute('put_attempts'));
        $this->assertSame(1, $this->countSent('PROPFIND'));
        $this->assertSame(0, $this->countSent('PUT'));
    }

    private function service(): PosteingangUploadService
    {
        return $this->app->make(PosteingangUploadService::class);
    }

    private function countSent(string $method): int
    {
        $count = 0;

        Http::assertSent(function (Request $request) use ($method, &$count): bool {
            if ($request->method() === $method) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    /**
     * Gefälschter DAV-Server: PROPFIND kennt die Dateien in $remote (exakter Pfad oder Präfix-Hook "*name_"),
     * PUT antwortet 201 (oder $putStatus) und legt die Datei ab, GET liefert den Inhalt.
     */
    private function fakeServer(ImmowareConnection $connection): void
    {
        $base = $this->basePathOf($connection);

        Http::fake(function (Request $request) use ($connection, $base): PromiseInterface {
            $path = $this->relativePath($request, $connection);
            $content = $this->remoteContent($path);

            if ($request->method() === 'PROPFIND') {
                if ($content === null) {
                    return Http::response('', 404);
                }

                return Http::response($this->multistatus($base, [
                    ['href' => $path, 'etag' => 'r-'.substr(hash('sha256', $content), 0, 8), 'length' => strlen($content), 'modified' => 'Sat, 12 Sep 2026 08:00:00 GMT'],
                ]), 207);
            }

            if ($request->method() === 'GET') {
                return $content === null ? Http::response('', 404) : Http::response($content, 200, ['Content-Type' => 'application/pdf']);
            }

            if ($request->method() === 'PUT') {
                if ($this->putTimesOut) {
                    throw new ConnectException('cURL error 28: Operation timed out after 60000 milliseconds', new PsrRequest('PUT', $request->url()));
                }

                if ($this->putStatus !== null) {
                    return Http::response('', $this->putStatus);
                }

                if ($content !== null) {
                    return Http::response('', 412);
                }

                $this->remote[$path] = $request->body();

                return Http::response('', 201);
            }

            return Http::response('', 405);
        });
    }

    private function remoteContent(string $path): ?string
    {
        if (isset($this->remote[$path])) {
            return $this->remote[$path];
        }

        foreach ($this->remote as $key => $content) {
            if (str_starts_with($key, '*') && str_starts_with($path, '/Posteingang/'.substr($key, 1))) {
                return $content;
            }
        }

        return null;
    }
}
