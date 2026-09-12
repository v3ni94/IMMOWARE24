<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Core\Enums\WriteOperationStatus;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\Models\Document;
use App\Modules\Estate\Models\CaseFile;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WriteOperation extends Model
{
    protected $table = 'write_operations';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WriteOperationStatus::class,
            'precheck_result' => 'array',
            'verify_result' => 'array',
            'size_bytes' => 'integer',
            'precheck_attempts' => 'integer',
            'verify_attempts' => 'integer',
            'put_attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public const string OPERATION_WEBDAV_CREATE = 'webdav_create';

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            if ($operation->getAttribute('operation_uuid') === null) {
                $operation->setAttribute('operation_uuid', (string) Str::uuid());
            }
        });

        // Ein Retry darf nie einen zweiten Upload erzeugen; Rücksprung aus sent/unknown ist verboten.
        static::updating(function (self $operation): void {
            if ((int) $operation->getAttribute('put_attempts') > 1) {
                throw new WriteBlockedException('put_attempts darf 1 nicht überschreiten.', self::OPERATION_WEBDAV_CREATE);
            }

            $original = $operation->getOriginal('status');
            $original = $original instanceof WriteOperationStatus ? $original : WriteOperationStatus::tryFrom((string) $original);
            $new = $operation->getAttribute('status');

            // Aus sent, unknown oder verified sind nur Endzustände erreichbar: failed, oder rejected bei 412 (Ziel existiert,
            // kein PUT hat gewirkt). Rücksprung auf pending oder prechecked ist verboten (Änderungsvermerk 12.09.2026).
            if ($original?->mayHaveReachedRemote() && $new instanceof WriteOperationStatus && ! $new->mayHaveReachedRemote() && ! in_array($new, [WriteOperationStatus::Failed, WriteOperationStatus::Rejected], true)) {
                throw new WriteBlockedException(sprintf('Statuswechsel von %s nach %s ist verboten.', $original->value, $new->value), self::OPERATION_WEBDAV_CREATE);
            }
        });
    }

    public static function idempotencyKey(int $connectionId, string $contentHash, string $intentKey): string
    {
        return hash('sha256', $connectionId.'|'.$contentHash.'|'.$intentKey);
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    /**
     * @return BelongsTo<CaseFile, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseFile::class, 'case_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
