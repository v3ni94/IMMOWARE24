<?php

declare(strict_types=1);

namespace Tests\Unit\Gmail;

use App\Modules\Gmail\Mime\HeaderDecoder;
use App\Modules\Gmail\Mime\MimeBuilder;
use App\Modules\Gmail\Mime\MimeParser;
use PHPUnit\Framework\TestCase;

final class MimeBuilderTest extends TestCase
{
    public function test_built_reply_round_trips_through_parser_with_threading_headers_and_attachment(): void
    {
        $builder = new MimeBuilder;
        $messageId = $builder->newMessageId('mail.muellerhv.de');

        $raw = $builder->build([
            'from' => 'hv@muellerhv.de',
            'from_name' => 'Hausverwaltung Müller GmbH',
            'to' => ['mieter@example.com'],
            'cc' => ['eigentümer@example.com'],
            'subject' => 'Re: Wasserschaden Küche',
            'text' => "Guten Tag,\nwir kümmern uns. Grüße äöü\n",
            'html' => '<p>Guten Tag,<br>wir kümmern uns. Grüße äöü</p>',
            'message_id' => $messageId,
            'in_reply_to' => '<abc@example.com>',
            'references' => ['<root@example.com>', '<abc@example.com>'],
            'attachments' => [['filename' => 'Bestätigung.pdf', 'mime_type' => 'application/pdf', 'content' => '%PDF-1.4 test']],
        ]);

        $this->assertStringContainsString("\r\nIn-Reply-To: <abc@example.com>\r\n", $raw);
        $this->assertStringContainsString('References: <root@example.com>', $raw);
        $this->assertStringContainsString('Message-ID: '.$messageId, $raw);
        $this->assertStringContainsString('Subject: =?UTF-8?B?', $raw);
        $this->assertMatchesRegularExpression('/^<[0-9a-f-]{36}@mail\.muellerhv\.de>$/', $messageId);

        $parsed = (new MimeParser(new HeaderDecoder))->parse($raw);

        $this->assertSame('Re: Wasserschaden Küche', $parsed->subject);
        $this->assertSame('hv@muellerhv.de', $parsed->sender()['email']);
        $this->assertSame('Hausverwaltung Müller GmbH', $parsed->sender()['name']);
        $this->assertSame('<abc@example.com>', $parsed->inReplyTo);
        $this->assertSame(['<root@example.com>', '<abc@example.com>'], $parsed->references);
        $this->assertStringContainsString('wir kümmern uns. Grüße äöü', (string) $parsed->text);
        $this->assertStringContainsString('<p>Guten Tag,<br>', (string) $parsed->html);
        $this->assertSame('Bestätigung.pdf', $parsed->attachments[0]['filename']);
        $this->assertSame('%PDF-1.4 test', $parsed->attachments[0]['content']);
    }

    public function test_base64url_is_reversible_without_padding(): void
    {
        $raw = "From: a@b\r\n\r\nx\xff\xfe";
        $encoded = MimeBuilder::base64url($raw);

        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertSame($raw, MimeBuilder::base64urlDecode($encoded));
    }
}
