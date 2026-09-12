<?php

declare(strict_types=1);

namespace Tests\Unit\Gmail;

use App\Modules\Gmail\Mime\HeaderDecoder;
use App\Modules\Gmail\Mime\MimeParser;
use PHPUnit\Framework\TestCase;

final class MimeParserTest extends TestCase
{
    private MimeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MimeParser(new HeaderDecoder);
    }

    public function test_parses_quoted_printable_latin1_with_umlauts_and_rfc2047_subject(): void
    {
        $raw = implode("\r\n", [
            'From: "Mieter, Jörg" <joerg@example.com>',
            'To: verwaltung@muellerhv.de, Zweiter <zwei@example.com>',
            'Subject: =?ISO-8859-1?Q?Wasserschaden_K=FCche?= =?UTF-8?B?IMOcYmVyc2Nod2VtbXVuZw==?=',
            'Date: Fri, 12 Sep 2026 10:15:00 +0200 (CEST)',
            'Message-ID: <abc123@example.com>',
            'In-Reply-To: <parent@example.com>',
            'References: <root@example.com>',
            ' <parent@example.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=iso-8859-1',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Sehr geehrte Damen und Herren,=0D=0Ain der K=FCche tropft es. Gr=FC=DFe =E4=F6=FC =',
            'Ende.',
        ]);

        $message = $this->parser->parse($raw);

        $this->assertSame('Wasserschaden Küche Überschwemmung', $message->subject);
        $this->assertSame('joerg@example.com', $message->sender()['email']);
        $this->assertSame('Mieter, Jörg', $message->sender()['name']);
        $this->assertCount(2, $message->to);
        $this->assertSame('zwei@example.com', $message->to[1]['email']);
        $this->assertSame('<abc123@example.com>', $message->messageId);
        $this->assertSame('<parent@example.com>', $message->inReplyTo);
        $this->assertSame(['<root@example.com>', '<parent@example.com>'], $message->references);
        $this->assertSame('2026-09-12 08:15:00', $message->date?->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('in der Küche tropft es. Grüße äöü Ende.', (string) $message->text);
        $this->assertNull($message->html);
        $this->assertFalse($message->hasAttachments());
    }

    public function test_parses_nested_multipart_with_base64_attachment_rfc2231_filename_and_inline_image(): void
    {
        $pdf = random_bytes(300);
        $png = "\x89PNG\r\n\x1a\nfake";

        $raw = implode("\r\n", [
            'From: absender@example.com',
            'To: verwaltung@muellerhv.de',
            'Subject: Rechnung',
            'Message-ID: <nested@example.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            'Vorspann, wird ignoriert.',
            '--outer',
            'Content-Type: multipart/alternative; boundary="alt"',
            '',
            '--alt',
            'Content-Type: text/plain; charset="utf-8"',
            'Content-Transfer-Encoding: 8bit',
            '',
            'Anbei die Rechnung für Müller.',
            '--alt',
            'Content-Type: multipart/related; boundary="rel"',
            '',
            '--rel',
            'Content-Type: text/html; charset="utf-8"',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode('<p>Anbei die <b>Rechnung</b> für Müller <img src="cid:logo@example"></p>'), 76, "\r\n"),
            '--rel',
            'Content-Type: image/png',
            'Content-Transfer-Encoding: base64',
            'Content-ID: <logo@example>',
            'Content-Disposition: inline; filename="logo.png"',
            '',
            base64_encode($png),
            '--rel--',
            '--alt--',
            '--outer',
            'Content-Type: application/pdf',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment;',
            " filename*0*=UTF-8''Rechnung%20M%C3%BCller%20;",
            ' filename*1*=Stra%C3%9Fe%202026.pdf',
            '',
            chunk_split(base64_encode($pdf), 76, "\r\n"),
            '--outer--',
            'Nachspann.',
        ]);

        $message = $this->parser->parse($raw);

        $this->assertSame('Anbei die Rechnung für Müller.', trim((string) $message->text));
        $this->assertStringContainsString('<b>Rechnung</b> für Müller', (string) $message->html);
        $this->assertCount(1, $message->attachments);
        $this->assertSame('Rechnung Müller Straße 2026.pdf', $message->attachments[0]['filename']);
        $this->assertSame('application/pdf', $message->attachments[0]['mime_type']);
        $this->assertSame($pdf, $message->attachments[0]['content']);
        $this->assertCount(1, $message->inline);
        $this->assertSame('logo@example', $message->inline[0]['content_id']);
        $this->assertSame($png, $message->inline[0]['content']);
        $this->assertSame('1.1.2.2', $message->inline[0]['part_id']);
        $this->assertSame(['1', '1.1', '1.1.1', '1.1.2', '1.1.2.1', '1.1.2.2', '1.2'], array_column($message->parts, 'part_id'));
    }

    public function test_header_decoder_handles_rfc2047_in_parameters_and_address_lists(): void
    {
        $decoder = new HeaderDecoder;

        $parsed = $decoder->parseParameterized('attachment; filename="=?UTF-8?Q?Best=C3=A4tigung=2Epdf?="');
        $this->assertSame('attachment', $parsed['value']);
        $this->assertSame('Bestätigung.pdf', $parsed['params']['filename']);

        $addresses = $decoder->parseAddressList('=?UTF-8?B?SGF1c3Zlcndh bHR1bmcgTcO8bGxlcg==?= <hv@muellerhv.de>, "Doe, John" <JOHN@Example.com>, plain@example.com');
        $this->assertSame('hv@muellerhv.de', $addresses[0]['email']);
        $this->assertSame('Doe, John', $addresses[1]['name']);
        $this->assertSame('john@example.com', $addresses[1]['email']);
        $this->assertNull($addresses[2]['name']);
    }

    public function test_message_without_headers_is_treated_as_plain_body(): void
    {
        $message = $this->parser->parse("Nur Text ohne Header\nzweite Zeile");

        $this->assertNull($message->messageId);
        $this->assertSame("Nur Text ohne Header\nzweite Zeile", $message->text);
    }
}
