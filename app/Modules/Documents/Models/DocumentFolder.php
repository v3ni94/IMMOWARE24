<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Enums\DocumentSyncMode;
use App\Core\Traits\BelongsToOrganization;
use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentFolder extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'document_folders';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'scan_priority' => 'integer',
            'scan_interval_seconds' => 'integer',
            'content_policy' => DocumentSyncMode::class,
            'last_scanned_at' => 'immutable_datetime',
            'last_change_seen_at' => 'immutable_datetime',
            'writable_by_hub' => 'boolean',
            'missing_since' => 'immutable_datetime',
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
     * @return BelongsTo<DocumentFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'parent_id');
    }

    /**
     * @return HasMany<DocumentFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(DocumentFolder::class, 'parent_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }
}
