<?php

declare(strict_types=1);

namespace App\Modules\Security\Console;

use App\Core\Enums\AuditSource;
use App\Core\Enums\Role;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\DTO\AuditActor;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Contracts\Hashing\Hasher;

final class CreateUserCommand extends Command
{
    protected $signature = 'hub:user:create
        {email : E-Mail-Adresse des Nutzers}
        {--role=read_only : Rolle (owner, administrator, developer, operator, read_only)}
        {--name= : Anzeigename}
        {--organization= : ID der Organisation, Standard: erste Organisation}
        {--password= : Passwort, sonst Abfrage per Prompt}';

    protected $description = 'Legt einen Hub-Nutzer mit E-Mail, Rolle und Passwort an.';

    public function handle(Hasher $hasher, AuditLogger $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = Role::tryFrom((string) $this->option('role'));

        if ($role === null || ! $role->canLogin()) {
            $this->error('Ungültige Rolle. Erlaubt: '.implode(', ', array_map(
                static fn (Role $r): string => $r->value,
                array_filter(Role::cases(), static fn (Role $r): bool => $r->canLogin()),
            )));

            return self::FAILURE;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Ungültige E-Mail-Adresse.');

            return self::FAILURE;
        }

        if (User::query()->allOrganizations()->where('email', $email)->exists()) {
            $this->error('Ein Nutzer mit dieser E-Mail-Adresse existiert bereits.');

            return self::FAILURE;
        }

        $organizationId = $this->option('organization') !== null
            ? (int) $this->option('organization')
            : Organization::query()->orderBy('id')->value('id');

        if ($organizationId === null || ! Organization::query()->whereKey($organizationId)->exists()) {
            $this->error('Keine gültige Organisation gefunden. Bitte --organization angeben.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? $this->secret('Passwort (mindestens 12 Zeichen)'));
        $minLength = (int) config('hub.security.login.password_min_length', 12);

        if (mb_strlen($password) < $minLength) {
            $this->error(sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', $minLength));

            return self::FAILURE;
        }

        $user = User::query()->create([
            'organization_id' => (int) $organizationId,
            'name' => (string) ($this->option('name') ?? strstr($email, '@', true) ?: $email),
            'email' => $email,
            'password' => $hasher->make($password),
            'role' => $role,
        ]);

        $audit->asActor(AuditActor::system())->record(
            'security.user.created',
            $user,
            [],
            ['email' => $email, 'role' => $role->value, 'organization_id' => (int) $organizationId],
            AuditSource::System,
        );

        $this->info(sprintf('Nutzer %s (ID %d) mit Rolle %s angelegt. Die 2FA-Einrichtung erfolgt beim ersten Login.', $email, (int) $user->getKey(), $role->value));

        return self::SUCCESS;
    }
}
