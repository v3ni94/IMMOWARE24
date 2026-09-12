<?php

declare(strict_types=1);

namespace App\Modules\Drive\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Estate\Models\Property;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zuordnung Objekt (properties) zu bestehendem Drive-Ordner. Es werden nie Ordner angelegt.
 *
 * Tabelle mail_drive_folder_mappings. Massenzuweisung offen ($guarded leer): Eingaben werden in Services validiert.
 */
class DriveFolderMapping extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_drive_folder_mappings';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * @return BelongsTo<DriveConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DriveConnection::class, 'drive_connection_id');
    }
}
