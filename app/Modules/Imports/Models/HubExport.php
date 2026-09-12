<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Imports\Enums\HubExportFormat;
use App\Modules\Imports\Enums\HubExportStatus;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asynchroner Export aus dem Hub (CSV oder JSON), chunked geschrieben, mit Status und Ablagepfad.
 */
class HubExport extends Model
{
    use BelongsToOrganization;

    protected $table = 'hub_exports';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'format' => HubExportFormat::class,
            'status' => HubExportStatus::class,
            'row_count' => 'integer',
            'size_bytes' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
