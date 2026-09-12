<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Cases\Exceptions\CaseLockedException;
use App\Modules\Cases\Models\CaseLock;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Bearbeitungssperre je Vorgang: wer bearbeitet gerade. Läuft ohne Heartbeat nach lock_ttl_seconds ab; eine
 * abgelaufene Sperre darf übernommen werden. Übergabe (handover) gibt die Sperre gezielt weiter.
 */
final class LockService
{
    public function __construct(
        private readonly CaseStatusLogger $log,
        private readonly Repository $config,
    ) {}

    public function acquire(MailCase $case, User $user, ?CarbonImmutable $now = null): CaseLock
    {
        $now ??= CarbonImmutable::now();
        $existing = CaseLock::query()->where('case_id', $case->getKey())->first();

        if ($existing instanceof CaseLock && (int) $existing->user_id !== (int) $user->getKey() && ! $existing->isExpired($now)) {
            throw new CaseLockedException((int) $case->getKey(), (int) $existing->user_id, $existing->loadMissing('user')->user?->getAttribute('name'), $existing->expires_at);
        }

        $expires = $now->addSeconds($this->ttl());

        if ($existing instanceof CaseLock) {
            $takenOver = (int) $existing->user_id !== (int) $user->getKey();
            $existing->forceFill(['user_id' => $user->getKey(), 'acquired_at' => $takenOver ? $now : $existing->acquired_at, 'heartbeat_at' => $now, 'expires_at' => $expires])->save();

            if ($takenOver) {
                $this->log->log($case, 'lock', 'expired', 'acquired', 'Abgelaufene Sperre übernommen.', $user->getKey());
            }

            return $existing;
        }

        $lock = CaseLock::query()->create([
            'case_id' => $case->getKey(),
            'user_id' => $user->getKey(),
            'acquired_at' => $now,
            'heartbeat_at' => $now,
            'expires_at' => $expires,
        ]);

        $this->log->log($case, 'lock', null, 'acquired', 'Bearbeitung begonnen.', $user->getKey());

        return $lock;
    }

    public function heartbeat(MailCase $case, User $user, ?CarbonImmutable $now = null): ?CaseLock
    {
        $now ??= CarbonImmutable::now();
        $lock = CaseLock::query()->where('case_id', $case->getKey())->where('user_id', $user->getKey())->first();

        if (! $lock instanceof CaseLock || $lock->isExpired($now)) {
            return null;
        }

        $lock->forceFill(['heartbeat_at' => $now, 'expires_at' => $now->addSeconds($this->ttl())])->save();

        return $lock;
    }

    public function release(MailCase $case, User $user): bool
    {
        $deleted = CaseLock::query()->where('case_id', $case->getKey())->where('user_id', $user->getKey())->delete();

        if ($deleted > 0) {
            $this->log->log($case, 'lock', 'acquired', 'released', 'Bearbeitung beendet.', $user->getKey());
        }

        return $deleted > 0;
    }

    /**
     * Übergabe der Sperre an eine andere Person.
     */
    public function handover(MailCase $case, User $from, User $to, ?CarbonImmutable $now = null): CaseLock
    {
        $now ??= CarbonImmutable::now();
        $lock = CaseLock::query()->where('case_id', $case->getKey())->first();

        if ($lock instanceof CaseLock && (int) $lock->user_id !== (int) $from->getKey() && ! $lock->isExpired($now)) {
            throw new CaseLockedException((int) $case->getKey(), (int) $lock->user_id, $lock->loadMissing('user')->user?->getAttribute('name'), $lock->expires_at);
        }

        $lock ??= new CaseLock(['case_id' => $case->getKey()]);
        $lock->forceFill(['user_id' => $to->getKey(), 'acquired_at' => $now, 'heartbeat_at' => $now, 'expires_at' => $now->addSeconds($this->ttl())])->save();

        $this->log->log($case, 'lock', (string) $from->getKey(), (string) $to->getKey(), 'Bearbeitung übergeben.', $from->getKey(), null, 'user', ['to_user_id' => $to->getKey()]);

        return $lock;
    }

    /**
     * Aktuelle Sperre, sofern nicht abgelaufen (Anzeige "wird bearbeitet von").
     */
    public function holder(MailCase $case, ?CarbonImmutable $now = null): ?CaseLock
    {
        $lock = CaseLock::query()->where('case_id', $case->getKey())->first();

        return $lock instanceof CaseLock && ! $lock->isExpired($now ?? CarbonImmutable::now()) ? $lock : null;
    }

    public function purgeExpired(?CarbonImmutable $now = null): int
    {
        return CaseLock::query()->where('expires_at', '<=', $now ?? CarbonImmutable::now())->delete();
    }

    private function ttl(): int
    {
        return max(30, (int) $this->config->get('hub.cases.lock_ttl_seconds', 300));
    }
}
