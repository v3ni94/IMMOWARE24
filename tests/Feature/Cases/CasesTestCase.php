<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Models\MailThread;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeinsame Helfer der Module Cases und Sla: Nachrichten anlegen (Empfangszeit = Gmail internalDate).
 */
abstract class CasesTestCase extends TestCase
{
    use RefreshDatabase;

    protected static int $sequence = 0;

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function inboundMessage(Mailbox $mailbox, array $attributes = []): MailMessage
    {
        self::$sequence++;
        // Speicherung UTC: Eloquent konvertiert keine Zeitzonen, deshalb hier explizit nach UTC.
        $receivedAt = CarbonImmutable::instance($attributes['received_at'] ?? CarbonImmutable::now()->subMinutes(2))->utc();
        $attributes['received_at'] = $receivedAt;
        $attributes['imported_at'] = CarbonImmutable::instance($attributes['imported_at'] ?? CarbonImmutable::now())->utc();
        $threadId = $attributes['gmail_thread_id'] ?? 'thread-'.self::$sequence;

        $thread = MailThread::query()->firstOrCreate(
            ['mailbox_id' => $mailbox->getKey(), 'gmail_thread_id' => $threadId],
            ['organization_id' => $mailbox->organization_id, 'first_message_at' => $receivedAt, 'last_message_at' => $receivedAt, 'message_count' => 1],
        );

        unset($attributes['gmail_thread_id']);

        return MailMessage::query()->create(array_merge([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->getKey(),
            'thread_id' => $thread->getKey(),
            'gmail_message_id' => 'gm-'.self::$sequence.'-'.uniqid(),
            'rfc_message_id' => '<msg-'.self::$sequence.'@example.com>',
            'rfc_message_id_hash' => hash('sha256', '<msg-'.self::$sequence.'@example.com>'),
            'direction' => 'inbound',
            'from_address' => 'mieter@example.com',
            'from_name' => 'Max Mieter',
            'subject' => 'Anfrage',
            'snippet' => null,
            'body_text' => 'Guten Tag, ich habe eine Frage.',
            'received_at' => $receivedAt,
            'checksum' => hash('sha256', 'msg-'.self::$sequence),
            'processing_status' => 'imported',
        ], $attributes));
    }

    /**
     * Gesendete Nachricht im Thread. Ohne $to geht sie an den Absender der eingehenden Nachricht (echte Antwort).
     *
     * @param  array<int, string>|null  $to
     */
    protected function outboundReply(MailMessage $inbound, ?CarbonImmutable $sentAt = null, ?array $to = null): MailMessage
    {
        $mailbox = $inbound->mailbox;
        $to ??= [(string) $inbound->from_address];

        return $this->inboundMessage($mailbox, [
            'gmail_thread_id' => $inbound->thread?->gmail_thread_id,
            'direction' => 'outbound',
            'from_address' => $mailbox->email_address,
            'to_json' => array_map(static fn (string $email): array => ['email' => $email, 'name' => null], $to),
            'in_reply_to' => $inbound->rfc_message_id,
            'subject' => 'Re: '.$inbound->subject,
            'body_text' => 'Vielen Dank, wir kümmern uns.',
            'received_at' => $sentAt ?? CarbonImmutable::now(),
        ]);
    }
}
