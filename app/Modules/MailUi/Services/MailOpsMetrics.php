<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Sync\Services\SyncMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Betriebskennzahlen des Mail-Dashboards: Push-Drosselungen (HTTP 429 am Pub/Sub-Endpunkt, Zähler in SyncMetrics)
 * und Watch-Ablauf je Postfach (mail_sync_states). Der Zähler wird vom Modul MailIntegration aus dem Log-Ereignis
 * mail.push.rate_limited befüllt; hier wird nur gelesen.
 */
final class MailOpsMetrics
{
    public const string PUSH_RATE_LIMITED = 'mail_push_429';

    /** @var array<string, string> */
    public const array WATCH_LABELS = [
        'none' => 'Kein Watch',
        'requested' => 'Angefordert',
        'active' => 'Aktiv',
        'expired' => 'Abgelaufen',
        'failed' => 'Fehlgeschlagen',
    ];

    public function __construct(
        private readonly SyncMetrics $metrics,
        private readonly Repository $config,
    ) {}

    public function pushRateLimited(): int
    {
        return (int) $this->metrics->value(self::PUSH_RATE_LIMITED);
    }

    /**
     * Postfächer der Organisation mit Watch-Problem: abgelaufen, fehlgeschlagen, ohne Watch oder Restlaufzeit unter
     * hub.gmail.push.watch_alert_hours. Postfächer ohne Zeile in mail_sync_states gelten als "Kein Watch".
     *
     * @return array<int, array{mailbox: string, status: string, label: string, expires_at: CarbonImmutable|null, level: string}>
     */
    public function watchProblems(int $organizationId): array
    {
        $threshold = CarbonImmutable::now()->addHours(max(1, (int) $this->config->get('hub.gmail.push.watch_alert_hours', 24)));
        $result = [];

        $mailboxes = Mailbox::query()->where('organization_id', $organizationId);
        $mailboxes->orderBy('label');

        foreach ($mailboxes->get(['id', 'label', 'email_address', 'status']) as $mailbox) {
            $state = MailSyncState::query()->where('mailbox_id', $mailbox->getKey())->first();
            $status = (string) ($state?->getAttribute('watch_status') ?? 'none');
            $expiration = $state?->getAttribute('watch_expiration');
            $expiresAt = $expiration instanceof \DateTimeInterface ? CarbonImmutable::instance($expiration) : null;
            $expiring = $status === 'active' && ($expiresAt === null || $expiresAt->lessThan($threshold));

            if ($status === 'active' && ! $expiring) {
                continue;
            }

            if ($status === 'requested' && $expiresAt !== null && $expiresAt->greaterThan($threshold)) {
                continue;
            }

            $level = in_array($status, ['expired', 'failed'], true) || ($expiresAt !== null && $expiresAt->isPast()) ? 'fail' : 'warn';
            $label = $expiring ? 'Läuft ab' : (self::WATCH_LABELS[$status] ?? $status);

            $result[] = [
                'mailbox' => (string) $mailbox->getAttribute('label'),
                'status' => $status,
                'label' => $label,
                'expires_at' => $expiresAt,
                'level' => $level,
            ];
        }

        return $result;
    }
}
