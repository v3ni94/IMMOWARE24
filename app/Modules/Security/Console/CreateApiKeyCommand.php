<?php

declare(strict_types=1);

namespace App\Modules\Security\Console;

use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\ApiKeyService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class CreateApiKeyCommand extends Command
{
    protected $signature = 'hub:api-key:create
        {name : Bezeichnung des Keys}
        {--scopes= : Kommagetrennte Scopes, z. B. properties:read,units:read}
        {--expires= : Ablaufdatum TT.MM.JJJJ oder JJJJ-MM-TT, Standard 12 Monate}
        {--organization= : ID der Organisation, Standard: erste Organisation}
        {--created-by= : E-Mail des anlegenden Nutzers}
        {--allowed-ips= : Kommagetrennte IPs oder CIDR-Bereiche}';

    protected $description = 'Erzeugt einen API-Key. Der Klartext wird genau einmal ausgegeben.';

    public function handle(ApiKeyService $service): int
    {
        $organizationId = $this->option('organization') !== null
            ? (int) $this->option('organization')
            : Organization::query()->orderBy('id')->value('id');

        if ($organizationId === null || ! Organization::query()->whereKey($organizationId)->exists()) {
            $this->error('Keine gültige Organisation gefunden. Bitte --organization angeben.');

            return self::FAILURE;
        }

        $scopesOption = (string) ($this->option('scopes') ?? '');
        $scopes = $scopesOption !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $scopesOption))))
            : (array) $this->choice('Scopes auswählen', $service->knownScopes(), null, null, true);

        $expires = $this->parseExpiry($this->option('expires'));

        if ($expires === null) {
            $this->error('Ungültiges Ablaufdatum. Erwartet TT.MM.JJJJ oder JJJJ-MM-TT.');

            return self::FAILURE;
        }

        $createdBy = null;

        if ($this->option('created-by') !== null) {
            $createdBy = User::query()->allOrganizations()->where('email', mb_strtolower(trim((string) $this->option('created-by'))))->first();

            if ($createdBy === null) {
                $this->error('Der angegebene Nutzer wurde nicht gefunden.');

                return self::FAILURE;
            }
        }

        $ipsOption = (string) ($this->option('allowed-ips') ?? '');
        $allowedIps = $ipsOption !== '' ? array_values(array_filter(array_map('trim', explode(',', $ipsOption)))) : null;

        try {
            $created = $service->create((int) $organizationId, (string) $this->argument('name'), $scopes, $expires, $createdBy, $allowedIps);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('API-Key angelegt. Der Klartext wird nur jetzt angezeigt und nicht gespeichert.');
        $this->table(['Feld', 'Wert'], [
            ['ID', (string) $created->apiKey->getKey()],
            ['Prefix', (string) $created->apiKey->getAttribute('prefix')],
            ['Scopes', implode(', ', (array) $created->apiKey->getAttribute('scopes'))],
            ['Gültig bis', $expires->timezone('Europe/Berlin')->format('d.m.Y H:i')],
        ]);
        $this->line('Key: '.$created->plainTextKey);

        return self::SUCCESS;
    }

    private function parseExpiry(mixed $option): ?CarbonImmutable
    {
        if ($option === null || $option === '') {
            return CarbonImmutable::now()->addMonths((int) config('hub.security.api_keys.max_lifetime_months', 12));
        }

        $value = trim((string) $option);

        foreach (['d.m.Y', 'Y-m-d'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($parsed instanceof CarbonImmutable && $parsed->format($format) === $value) {
                return $parsed->endOfDay();
            }
        }

        return null;
    }
}
