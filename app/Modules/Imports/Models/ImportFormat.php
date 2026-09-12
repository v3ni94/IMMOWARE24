<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Modules\Security\Models\User;
use App\Modules\Sync\Models\ExternalPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportFormat extends Model
{
    protected $table = 'import_formats';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'header_columns' => 'array',
            'column_mapping' => 'array',
            'key_schema' => 'array',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ExternalPayload, $this>
     */
    public function samplePayload(): BelongsTo
    {
        return $this->belongsTo(ExternalPayload::class, 'sample_payload_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return HasMany<ImportFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ImportFile::class, 'import_format_id');
    }
}
