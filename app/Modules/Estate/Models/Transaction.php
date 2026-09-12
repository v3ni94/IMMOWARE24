<?php

declare(strict_types=1);

namespace App\Modules\Estate\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use App\Modules\Sync\Models\ExternalPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use BelongsToOrganization, HasExternalIdentity;

    protected $table = 'transactions';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booking_date' => 'immutable_date',
            'is_duplicate' => 'boolean',
            'value_date' => 'immutable_date',
            'amount_cents' => 'integer',
            'occurrence_no' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<ExternalPayload, $this>
     */
    public function importPayload(): BelongsTo
    {
        return $this->belongsTo(ExternalPayload::class, 'import_payload_id');
    }
}
