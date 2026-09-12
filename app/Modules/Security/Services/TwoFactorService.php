<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Core\Enums\AuditSource;
use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use Illuminate\Http\Request;

/**
 * Einrichtung, Bestätigung und Prüfung der Zwei-Faktor-Authentifizierung (TOTP plus Wiederherstellungscodes).
 */
final class TwoFactorService
{
    public function __construct(
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Startet die Einrichtung: neues Secret, noch nicht bestätigt. Vorhandene Bestätigung bleibt unberührt,
     * bis der neue Code bestätigt wurde.
     */
    public function beginSetup(User $user): string
    {
        $secret = $this->totp->generateSecret();

        $user->forceFill(['totp_secret' => $secret])->save();

        return $secret;
    }

    public function otpauthUri(User $user): string
    {
        $secret = (string) $user->getAttribute('totp_secret');

        return $this->totp->otpauthUri($secret, (string) $user->getAttribute('email'), (string) config('hub.security.totp.issuer', 'Immoware Hub'));
    }

    /**
     * Bestätigt die Einrichtung mit einem gültigen Code und erzeugt Wiederherstellungscodes (Klartext einmalig).
     *
     * @return array<int, string>|null
     */
    public function confirmSetup(User $user, string $code, ?Request $request = null): ?array
    {
        $secret = $user->getAttribute('totp_secret');

        if (! is_string($secret) || $secret === '' || ! $this->totp->verify($secret, $code)) {
            return null;
        }

        $codes = $this->recoveryCodes->generate((int) config('hub.security.totp.recovery_codes', 10));

        $user->forceFill([
            'totp_confirmed_at' => now()->toImmutable(),
            'recovery_codes' => $this->recoveryCodes->hashAll($codes),
        ])->save();

        $this->audit->record('security.two_factor.enabled', $user, [], ['recovery_codes' => count($codes)], AuditSource::User, request: $request);

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user, ?Request $request = null): array
    {
        $codes = $this->recoveryCodes->generate((int) config('hub.security.totp.recovery_codes', 10));

        $user->forceFill(['recovery_codes' => $this->recoveryCodes->hashAll($codes)])->save();

        $this->audit->record('security.two_factor.recovery_codes_regenerated', $user, [], ['count' => count($codes)], AuditSource::User, request: $request);

        return $codes;
    }

    public function disable(User $user, ?Request $request = null): void
    {
        $user->forceFill([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'recovery_codes' => null,
        ])->save();

        $this->audit->record('security.two_factor.disabled', $user, [], [], AuditSource::User, request: $request);
    }

    /**
     * Prüft einen TOTP-Code gegen das bestätigte Secret.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->getAttribute('totp_secret');

        return $user->hasConfirmedTotp() && is_string($secret) && $secret !== '' && $this->totp->verify($secret, $code);
    }

    /**
     * Verbraucht einen Wiederherstellungscode. Jeder Code ist genau einmal gültig.
     */
    public function consumeRecoveryCode(User $user, string $code, ?Request $request = null): bool
    {
        $hashes = $user->getAttribute('recovery_codes');

        if (! is_array($hashes) || $hashes === []) {
            return false;
        }

        $index = $this->recoveryCodes->match($hashes, $code);

        if ($index === null) {
            return false;
        }

        unset($hashes[$index]);
        $user->forceFill(['recovery_codes' => array_values($hashes)])->save();

        $this->audit->record('security.two_factor.recovery_code_used', $user, [], ['remaining' => count($hashes)], AuditSource::User, request: $request);

        return true;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        $hashes = $user->getAttribute('recovery_codes');

        return is_array($hashes) ? count($hashes) : 0;
    }

    /**
     * Rollen, die für Admin-Routen eine bestätigte 2FA benötigen.
     */
    public function isRequiredFor(Role $role): bool
    {
        $exempt = (array) config('hub.security.totp.exempt_roles', ['read_only']);

        return ! in_array($role->value, $exempt, true);
    }
}
