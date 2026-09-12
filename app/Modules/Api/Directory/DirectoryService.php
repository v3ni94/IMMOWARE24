<?php

declare(strict_types=1);

namespace App\Modules\Api\Directory;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactRole;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Telefonbuch aus dem Kontaktspiegel: Name, Firma, Telefon, Mobil, Rolle, Objekt, Einheit.
 * Zusammengeführte, gelöschte und pseudonymisierte Kontakte werden ausgeblendet.
 */
final class DirectoryService
{
    /** @var array<int, string> */
    private const array MOBILE_TYPES = ['cell', 'mobile', 'mobil', 'handy'];

    /**
     * @return LengthAwarePaginator<int, Contact>
     */
    public function paginate(?string $q, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Contact::query()
            ->whereNull('merged_into_id')
            ->whereNull('personal_data_erased_at')
            ->with(['roles.property:id,name,immoware_object_number', 'roles.unit:id,unit_number', 'company:id,name'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');

        $roles = (array) config('hub.api.directory.roles', []);

        if ($roles !== []) {
            $query->whereHas('roles', static fn (Builder $r) => $r->whereIn('role', $roles));
        }

        if ($q !== null && trim($q) !== '') {
            $this->applySearch($query, trim($q));
        }

        /** @var LengthAwarePaginator<int, Contact> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function entry(Contact $contact): DirectoryEntry
    {
        $phones = [];
        $mobiles = [];

        foreach ((array) $contact->getAttribute('phones') as $phone) {
            [$type, $value] = $this->typedValue($phone);

            if ($value === null) {
                continue;
            }

            if (in_array(strtolower($type ?? ''), self::MOBILE_TYPES, true)) {
                $mobiles[] = $value;
            } else {
                $phones[] = $value;
            }
        }

        $emails = [];

        foreach ((array) $contact->getAttribute('emails') as $email) {
            [, $value] = $this->typedValue($email);

            if ($value !== null) {
                $emails[] = $value;
            }
        }

        $roles = [];

        foreach ($contact->getRelation('roles') as $role) {
            if (! $role instanceof ContactRole) {
                continue;
            }

            $property = $role->getRelation('property');
            $unit = $role->getRelation('unit');

            $roleValue = $role->getAttribute('role');

            $roles[] = [
                'role' => $roleValue instanceof \BackedEnum ? (string) $roleValue->value : (string) $roleValue,
                'property_id' => $property !== null ? (int) $property->getKey() : null,
                'property' => $property?->getAttribute('name'),
                'unit_id' => $unit !== null ? (int) $unit->getKey() : null,
                'unit' => $unit?->getAttribute('unit_number'),
            ];
        }

        $company = $contact->getAttribute('company_name') ?? $contact->getRelation('company')?->getAttribute('name');
        $first = $contact->getAttribute('first_name');
        $last = $contact->getAttribute('last_name');
        $display = trim(implode(' ', array_filter([$first, $last], static fn ($v) => is_string($v) && $v !== '')));

        if ($display === '') {
            $display = is_string($company) && $company !== '' ? $company : 'Kontakt '.$contact->getKey();
        }

        $updated = $contact->getAttribute('updated_at');

        return new DirectoryEntry(
            id: (int) $contact->getKey(),
            displayName: $display,
            firstName: is_string($first) ? $first : null,
            lastName: is_string($last) ? $last : null,
            company: is_string($company) ? $company : null,
            phones: array_values(array_unique($phones)),
            mobiles: array_values(array_unique($mobiles)),
            emails: array_values(array_unique($emails)),
            roles: $roles,
            updatedAt: $updated instanceof \DateTimeInterface ? CarbonImmutable::instance($updated)->utc()->toIso8601ZuluString('millisecond') : null,
        );
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applySearch(Builder $query, string $q): void
    {
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $digitNeedle = $digits !== '' && strlen($digits) >= 3 ? '%'.$digits.'%' : null;

        $query->where(static function (Builder $where) use ($needle, $digitNeedle): void {
            $where->where('contacts.first_name', 'like', $needle)
                ->orWhere('contacts.last_name', 'like', $needle)
                ->orWhere('contacts.company_name', 'like', $needle)
                ->orWhereHas('company', static fn (Builder $c) => $c->where('name', 'like', $needle))
                ->orWhereHas('roles', static function (Builder $r) use ($needle): void {
                    $r->where('contact_roles.role', 'like', $needle)
                        ->orWhereHas('property', static fn (Builder $p) => $p->where('name', 'like', $needle)->orWhere('immoware_object_number', 'like', $needle))
                        ->orWhereHas('unit', static fn (Builder $u) => $u->where('unit_number', 'like', $needle));
                });

            if ($digitNeedle !== null) {
                $where->orWhere('contacts.phones', 'like', $digitNeedle);
            }
        });
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function typedValue(mixed $item): array
    {
        if (is_string($item)) {
            return [null, $item !== '' ? $item : null];
        }

        if (is_array($item)) {
            $value = $item['value'] ?? $item['number'] ?? $item['address'] ?? null;
            $type = $item['type'] ?? null;

            return [is_string($type) ? $type : null, is_string($value) && $value !== '' ? $value : null];
        }

        return [null, null];
    }
}
