<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Security\DTO\AuditActor;
use App\Modules\Security\Mail\NewLoginLocationMail;
use App\Modules\Security\Models\User;
use App\Modules\Security\Models\UserLoginIp;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Http\Request;

/**
 * Anmeldung mit E-Mail und Passwort, Fehlversuchszähler, Kontosperre und Hinweis bei neuer IP.
 */
final class LoginService
{
    public const string SESSION_TWO_FACTOR_VERIFIED = 'security.two_factor_verified_at';

    /** Anmeldezeitpunkt der Sitzung, Grundlage der absoluten Sitzungsdauer (EnforceAbsoluteSessionLifetime). */
    public const string SESSION_LOGIN_AT = 'security.login_at';

    /** Zeitpunkt der letzten erneuten Authentifizierung per Passwort oder Code (Middleware 2fa.fresh). */
    public const string SESSION_REAUTHENTICATED_AT = 'security.reauthenticated_at';

    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly Hasher $hasher,
        private readonly AuditLogger $audit,
        private readonly PepperedHasher $pepper,
        private readonly MailFactory $mail,
    ) {}

    /**
     * Gibt den angemeldeten Nutzer zurück oder null. Sperren und fehlende Rechte werden als Fehlversuch behandelt.
     */
    public function attempt(string $email, string $password, Request $request, bool $remember = false): ?User
    {
        $email = mb_strtolower(trim($email));
        $user = User::query()->allOrganizations()->where('email', $email)->first();

        if ($user === null) {
            $this->audit->record('security.login.failed', null, [], ['email_hash' => $this->pepper->hash($email), 'reason' => 'unknown_user'], AuditSource::User, request: $request, actor: AuditActor::system());

            return null;
        }

        if ($user->isDisabled() || ! $user->role->canLogin()) {
            $this->audit->record('security.login.failed', $user, [], ['reason' => 'disabled'], AuditSource::User, request: $request, actor: AuditActor::system());

            return null;
        }

        if ($user->isLocked()) {
            $this->audit->record('security.login.failed', $user, [], ['reason' => 'locked'], AuditSource::User, request: $request, actor: AuditActor::system());

            return null;
        }

        if (! $this->hasher->check($password, (string) $user->getAttribute('password'))) {
            $this->registerFailure($user, $request);

            return null;
        }

        $this->guard->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget([self::SESSION_TWO_FACTOR_VERIFIED, self::SESSION_REAUTHENTICATED_AT]);
        $request->session()->put(self::SESSION_LOGIN_AT, now()->toIso8601String());

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now()->toImmutable(),
        ])->save();

        $this->audit->record('security.login.succeeded', $user, [], ['remember' => $remember], AuditSource::User, request: $request, actor: AuditActor::user((int) $user->getKey(), $user->organization_id));

        $this->trackIp($user, $request);

        return $user;
    }

    public function logout(Request $request): void
    {
        $user = $this->guard->user();

        if ($user instanceof User) {
            $this->audit->record('security.logout', $user, [], [], AuditSource::User, request: $request);
        }

        $this->guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function isLockedOut(string $email): bool
    {
        $user = User::query()->allOrganizations()->where('email', mb_strtolower(trim($email)))->first();

        return $user !== null && $user->isLocked();
    }

    private function registerFailure(User $user, Request $request): void
    {
        $max = (int) config('hub.security.login.max_attempts', 5);
        $lockoutMinutes = (int) config('hub.security.login.lockout_minutes', 15);

        $count = (int) $user->getAttribute('failed_login_count') + 1;
        $attributes = ['failed_login_count' => $count];
        $after = ['reason' => 'bad_password', 'failed_login_count' => $count];

        if ($count >= $max) {
            $attributes['locked_until'] = CarbonImmutable::now()->addMinutes($lockoutMinutes);
            $attributes['failed_login_count'] = 0;
            $after['locked_until'] = $attributes['locked_until']->toIso8601String();
        }

        $user->forceFill($attributes)->save();

        $this->audit->record(
            $count >= $max ? 'security.account.locked' : 'security.login.failed',
            $user,
            [],
            $after,
            AuditSource::User,
            request: $request,
            actor: AuditActor::system(),
        );
    }

    private function trackIp(User $user, Request $request): void
    {
        $ip = $request->ip();

        if ($ip === null || $ip === '') {
            return;
        }

        $hash = (string) $this->pepper->hashIp($ip);
        $now = now()->toImmutable();

        $known = UserLoginIp::query()->where('user_id', $user->getKey())->where('ip_address_hash', $hash)->first();

        if ($known !== null) {
            $known->forceFill(['last_seen_at' => $now])->save();

            return;
        }

        $isFirstLoginEver = ! UserLoginIp::query()->where('user_id', $user->getKey())->exists();

        UserLoginIp::query()->create([
            'user_id' => $user->getKey(),
            'ip_address_hash' => $hash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        if ($isFirstLoginEver || ! config('hub.security.login.notify_new_ip', true)) {
            return;
        }

        $this->audit->record('security.login.new_ip', $user, [], ['notified' => true], AuditSource::User, request: $request);

        $this->mail->mailer((string) config('hub.security.login.notification_mailer', 'log'))
            ->to((string) $user->getAttribute('email'))
            ->send(new NewLoginLocationMail(
                user: $user,
                maskedIp: NewLoginLocationMail::maskIp($ip),
                userAgent: mb_substr((string) $request->userAgent(), 0, 200),
                occurredAt: $now->timezone('Europe/Berlin')->format('d.m.Y H:i'),
            ));
    }
}
