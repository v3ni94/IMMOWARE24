<?php

declare(strict_types=1);

namespace App\Modules\Estate\Models;

use App\Core\Support\HashedIdentifier;
use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use BelongsToOrganization, HasExternalIdentity;

    protected $table = 'bank_accounts';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // iban_hash ist HMAC-SHA256 mit Pepper über die normalisierte IBAN (02-data-model.md, 08-security.md 2.2),
        // nie reines SHA-256; wird bei jeder Änderung der IBAN neu abgeleitet.
        static::saving(function (self $account): void {
            if (! $account->isDirty('iban') && (string) $account->getAttribute('iban_hash') !== '') {
                return;
            }

            $iban = $account->getAttribute('iban');
            $hash = is_string($iban) ? app(HashedIdentifier::class)->iban($iban) : null;
            $account->setAttribute('iban_hash', $hash ?? '');
        });
    }

    /**
     * Maskierte Darstellung: nur Länderkennung und letzte vier Stellen bleiben lesbar.
     */
    public static function maskIban(string $iban): string
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', $iban));
        $length = strlen($clean);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return substr($clean, 0, 2).str_repeat('*', $length - 6).substr($clean, -4);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'bank_account_id');
    }
}
