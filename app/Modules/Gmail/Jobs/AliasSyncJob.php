<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Täglicher Abgleich der Send-as-Aliasse je Postfach (Zeitplan zentral in routes/console.php, Queue mail-sync).
 * Quelle: users.settings.sendAs.list über den Provider (GmailProvider::listSendAs, Scope gmail.settings.basic);
 * die Feldnamen sendAsEmail, displayName, replyToAddress, isDefault, isPrimary, verificationStatus stammen aus
 * Snippets (docs/mail/research/gmail-api.md) und sind vor Produktivbetrieb am Original zu prüfen.
 *
 * Regeln: Es werden nur verifizierte Aliasse angelegt (verificationStatus accepted) sowie die Primäradresse, für die
 * Gmail laut Snippets keinen Verifikationsstatus liefert. Nicht verifizierte Aliasse werden nicht angelegt; sind sie
 * bereits vorhanden, wird ihr Status übernommen, damit SendService sie ablehnt. Aliasse, die Gmail nicht mehr
 * liefert, werden nicht gelöscht (kein Hard Delete auf Spiegeldaten), sondern auf verification_status missing gesetzt.
 * Die Gesellschaftszuordnung (legal_entity_code) stammt aus dem Postfach und wird bei bestehenden Aliassen nie
 * überschrieben; die Signatur kommt aus dem CI-Skill, nicht aus Gmail.
 */
class AliasSyncJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_MISSING = 'missing';

    public function __construct(public readonly ?int $mailboxId = null)
    {
        $this->applyGmailRetryConfig();
    }

    public function handle(GmailProviderInterface $provider): void
    {
        if ($this->mailboxId === null) {
            Mailbox::query()->withoutGlobalScopes()
                ->whereIn('status', ['active', 'degraded'])
                ->whereNotNull('oauth_refresh_token')
                ->pluck('id')
                ->each(static function (mixed $id): void {
                    self::dispatch((int) $id);
                });

            return;
        }

        $mailbox = Mailbox::query()->withoutGlobalScopes()->find($this->mailboxId);

        if (! $mailbox instanceof Mailbox || ! in_array($mailbox->getAttribute('status'), ['active', 'degraded'], true)) {
            return;
        }

        try {
            $remote = $provider->listSendAs((int) $mailbox->getKey());
        } catch (Throwable $exception) {
            $this->handleGmailFailure($exception, $this->mailboxId);

            return;
        }

        $this->apply($mailbox, $remote);
    }

    /**
     * @param  array<int, array{send_as_email: string, display_name: ?string, reply_to: ?string, is_default: bool, is_primary: bool, verification_status: string}>  $remote
     * @return array{created: int, updated: int, missing: int, skipped: int}
     */
    public function apply(Mailbox $mailbox, array $remote): array
    {
        $now = CarbonImmutable::now();
        $stats = ['created' => 0, 'updated' => 0, 'missing' => 0, 'skipped' => 0];
        $seen = [];

        $existing = MailboxAlias::query()->where('mailbox_id', $mailbox->getKey())->get()->keyBy(static fn (MailboxAlias $alias): string => strtolower((string) $alias->getAttribute('send_as_email')));

        foreach ($remote as $entry) {
            $email = strtolower(trim((string) ($entry['send_as_email'] ?? '')));

            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }

            $seen[$email] = true;
            $isPrimary = (bool) ($entry['is_primary'] ?? false);
            $status = strtolower(trim((string) ($entry['verification_status'] ?? 'unknown')));
            $verified = $isPrimary || $status === self::STATUS_ACCEPTED;
            $alias = $existing->get($email);

            $attributes = [
                'display_name' => isset($entry['display_name']) ? mb_substr((string) $entry['display_name'], 0, 200) : null,
                'reply_to' => isset($entry['reply_to']) ? mb_substr(strtolower((string) $entry['reply_to']), 0, 254) : null,
                'is_default' => (bool) ($entry['is_default'] ?? false),
                'is_primary' => $isPrimary,
                'verification_status' => $verified ? self::STATUS_ACCEPTED : mb_substr($status, 0, 16),
                'synced_at' => $now,
            ];

            if ($alias instanceof MailboxAlias) {
                $alias->forceFill($attributes)->save();
                $stats['updated']++;

                continue;
            }

            if (! $verified) {
                $stats['skipped']++;

                continue;
            }

            MailboxAlias::query()->create($attributes + [
                'mailbox_id' => $mailbox->getKey(),
                'send_as_email' => $email,
                'legal_entity_code' => (string) $mailbox->getAttribute('legal_entity_code'),
            ]);
            $stats['created']++;
        }

        foreach ($existing as $email => $alias) {
            if (isset($seen[$email]) || (string) $alias->getAttribute('verification_status') === self::STATUS_MISSING) {
                continue;
            }

            $alias->forceFill(['verification_status' => self::STATUS_MISSING, 'is_default' => false, 'synced_at' => $now])->save();
            $stats['missing']++;
        }

        Log::info('Gmail: Alias-Abgleich abgeschlossen.', ['mailbox_id' => $mailbox->getKey()] + $stats);

        return $stats;
    }

    public function failed(Throwable $exception): void
    {
        if ($this->mailboxId === null) {
            return;
        }

        Log::error('Gmail: Alias-Abgleich endgültig fehlgeschlagen.', ['mailbox_id' => $this->mailboxId, 'reason' => $exception->getMessage()]);
        $this->storeInDlq($exception, ['mailboxId' => $this->mailboxId]);
    }
}
