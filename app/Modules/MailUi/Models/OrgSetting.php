<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Models;

use App\Core\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Organisationseinstellung der Mail-Bearbeitung (Schlüssel-Wert, JSON). Tabelle mail_org_settings.
 * Massenzuweisung offen ($guarded leer): nur OrgSettings schreibt nach Validierung in FormRequests.
 */
class OrgSetting extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_org_settings';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value_json' => 'array'];
    }
}
