<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aktive Sitzungen eines Nutzers (Session-Treiber database) anzeigen und beenden.
 */
final class SessionManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Collection<int, array{id: string, ip_address: string|null, user_agent: string|null, last_activity: CarbonImmutable, is_current: bool}>
     */
    public function activeSessions(User $user, ?string $currentSessionId = null): Collection
    {
        $lifetime = (int) config('session.lifetime', 120);
        $threshold = CarbonImmutable::now()->subMinutes($lifetime)->getTimestamp();

        return DB::table($this->table())
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $threshold)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'ip_address' => isset($row->ip_address) ? (string) $row->ip_address : null,
                'user_agent' => isset($row->user_agent) ? (string) $row->user_agent : null,
                'last_activity' => CarbonImmutable::createFromTimestamp((int) $row->last_activity),
                'is_current' => $currentSessionId !== null && (string) $row->id === $currentSessionId,
            ])
            ->values();
    }

    /**
     * Beendet alle Sitzungen außer der aktuellen. Gibt die Anzahl der beendeten Sitzungen zurück.
     */
    public function logoutOtherSessions(User $user, string $currentSessionId, ?Request $request = null): int
    {
        $deleted = DB::table($this->table())
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $this->audit->record('security.sessions.others_terminated', $user, [], ['terminated' => $deleted], AuditSource::User, request: $request);

        return $deleted;
    }

    /**
     * Beendet alle Sitzungen eines Nutzers, z. B. bei Rollenwechsel oder Sperre.
     */
    public function logoutAllSessions(User $user, ?Request $request = null): int
    {
        $deleted = DB::table($this->table())->where('user_id', $user->getKey())->delete();

        $this->audit->record('security.sessions.all_terminated', $user, [], ['terminated' => $deleted], AuditSource::User, request: $request);

        return $deleted;
    }

    private function table(): string
    {
        return (string) config('hub.security.sessions.table', 'sessions');
    }
}
