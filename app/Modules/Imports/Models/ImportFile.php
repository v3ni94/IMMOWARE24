<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Models\SyncRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportFile extends Model
{
    use BelongsToOrganization;

    protected $table = 'import_files';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'errors' => 'array',
            'is_full_export' => 'boolean',
            'size_bytes' => 'integer',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_failed' => 'integer',
            'rows_rejected' => 'integer',
            'rows_duplicate' => 'integer',
            'as_of_date' => 'immutable_date',
            'exported_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<ImportFormat, $this>
     */
    public function format(): BelongsTo
    {
        return $this->belongsTo(ImportFormat::class, 'import_format_id');
    }

    /**
     * @return BelongsTo<ExternalPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(ExternalPayload::class, 'payload_id');
    }

    /**
     * @return BelongsTo<SyncRun, $this>
     */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
