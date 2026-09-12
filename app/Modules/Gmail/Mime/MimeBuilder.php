<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Mime;

use Illuminate\Support\Str;

/**
 * Baut RFC-konforme MIME-Nachrichten für Entwürfe (RFC 5322 Header, RFC 2047 Header-Kodierung, multipart/alternative
 * für Text und HTML, multipart/mixed für Anhänge, multipart/related für Inline-Bilder, quoted-printable für Text,
 * base64 für Binärdaten). In-Reply-To und References stehen im Raw, weil die Gmail-API sie nur dort auswertet
 * (aus Snippets, vor Produktivbetrieb am Original zu prüfen). Ausgabe mit CRLF.
 */
final class MimeBuilder
{
    /**
     * @param  array{
     *     from: string, from_name?: ?string, to: array<int, string>, cc?: array<int, string>, bcc?: array<int, string>,
     *     reply_to?: ?string, subject: string, text: string, html?: ?string, message_id: string,
     *     in_reply_to?: ?string, references?: array<int, string>, date?: ?string,
     *     attachments?: array<int, array{filename: string, mime_type: string, content: string, content_id?: ?string}>
     * }  $message
     */
    public function build(array $message): string
    {
        $headers = [];
        $headers[] = 'From: '.$this->formatAddress($message['from'], $message['from_name'] ?? null);
        $headers[] = 'To: '.implode(', ', array_map(fn (string $a): string => $this->formatAddress($a), $message['to']));

        if (($message['cc'] ?? []) !== []) {
            $headers[] = 'Cc: '.implode(', ', array_map(fn (string $a): string => $this->formatAddress($a), $message['cc']));
        }

        if (($message['bcc'] ?? []) !== []) {
            $headers[] = 'Bcc: '.implode(', ', array_map(fn (string $a): string => $this->formatAddress($a), $message['bcc']));
        }

        if (isset($message['reply_to']) && $message['reply_to'] !== '') {
            $headers[] = 'Reply-To: '.$this->formatAddress($message['reply_to']);
        }

        $headers[] = 'Subject: '.$this->encodeHeader($message['subject']);
        $headers[] = 'Date: '.($message['date'] ?? gmdate(DATE_RFC2822));
        $headers[] = 'Message-ID: '.$message['message_id'];

        if (isset($message['in_reply_to']) && $message['in_reply_to'] !== '') {
            $headers[] = 'In-Reply-To: '.$message['in_reply_to'];
        }

        if (($message['references'] ?? []) !== []) {
            $headers[] = 'References: '.$this->foldList($message['references']);
        }

        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: Immoware Hub Mail';

        $attachments = $message['attachments'] ?? [];
        $inline = array_values(array_filter($attachments, static fn (array $a): bool => ($a['content_id'] ?? null) !== null));
        $regular = array_values(array_filter($attachments, static fn (array $a): bool => ($a['content_id'] ?? null) === null));

        $bodyPart = $this->buildBodyPart($message['text'], $message['html'] ?? null, $inline);

        if ($regular === []) {
            return implode("\r\n", $headers)."\r\n".$bodyPart;
        }

        $boundary = $this->boundary('mixed');
        $out = implode("\r\n", $headers)."\r\n";
        $out .= 'Content-Type: multipart/mixed; boundary="'.$boundary.'"'."\r\n\r\n";
        $out .= '--'.$boundary."\r\n".$bodyPart."\r\n";

        foreach ($regular as $attachment) {
            $out .= '--'.$boundary."\r\n".$this->attachmentPart($attachment, 'attachment')."\r\n";
        }

        $out .= '--'.$boundary."--\r\n";

        return $out;
    }

    public function newMessageId(string $domain): string
    {
        return '<'.Str::uuid()->toString().'@'.$domain.'>';
    }

    public static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        return (string) base64_decode($padded, false);
    }

    /**
     * RFC 2047 B-Kodierung für Nicht-ASCII, sonst unverändert.
     */
    public function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        $chunks = [];

        foreach (mb_str_split($value, 20, 'UTF-8') as $chunk) {
            $chunks[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';
        }

        return implode("\r\n ", $chunks);
    }

    /**
     * @param  array<int, array{filename: string, mime_type: string, content: string, content_id?: ?string}>  $inline
     */
    private function buildBodyPart(string $text, ?string $html, array $inline): string
    {
        $textPart = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".$this->quotedPrintable($text);

        if ($html === null || trim($html) === '') {
            return $textPart;
        }

        $htmlPart = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".$this->quotedPrintable($html);

        if ($inline !== []) {
            $related = $this->boundary('related');
            $htmlRelated = 'Content-Type: multipart/related; boundary="'.$related.'"'."\r\n\r\n";
            $htmlRelated .= '--'.$related."\r\n".$htmlPart."\r\n";

            foreach ($inline as $image) {
                $htmlRelated .= '--'.$related."\r\n".$this->attachmentPart($image, 'inline')."\r\n";
            }

            $htmlRelated .= '--'.$related.'--';
            $htmlPart = $htmlRelated;
        }

        $alternative = $this->boundary('alt');

        return 'Content-Type: multipart/alternative; boundary="'.$alternative.'"'."\r\n\r\n"
            .'--'.$alternative."\r\n".$textPart."\r\n"
            .'--'.$alternative."\r\n".$htmlPart."\r\n"
            .'--'.$alternative.'--';
    }

    /**
     * @param  array{filename: string, mime_type: string, content: string, content_id?: ?string}  $attachment
     */
    private function attachmentPart(array $attachment, string $disposition): string
    {
        $filename = $attachment['filename'];
        $asciiName = preg_match('/[^\x20-\x7E]/', $filename) !== 1;
        $nameParam = $asciiName
            ? 'filename="'.addcslashes($filename, '"\\').'"'
            : "filename*=UTF-8''".rawurlencode($filename);

        $headers = 'Content-Type: '.$attachment['mime_type'].'; name="'.($asciiName ? addcslashes($filename, '"\\') : $this->encodeHeader($filename)).'"'."\r\n";
        $headers .= 'Content-Transfer-Encoding: base64'."\r\n";
        $headers .= 'Content-Disposition: '.$disposition.'; '.$nameParam."\r\n";

        if (($attachment['content_id'] ?? null) !== null) {
            $headers .= 'Content-ID: <'.$attachment['content_id'].'>'."\r\n";
        }

        return $headers."\r\n".chunk_split(base64_encode($attachment['content']), 76, "\r\n");
    }

    private function quotedPrintable(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace("\n", "\r\n", str_replace("\r\n", "\n", quoted_printable_encode($text)));
    }

    private function formatAddress(string $email, ?string $name = null): string
    {
        $email = trim($email);

        if ($name === null || trim($name) === '') {
            return $email;
        }

        $name = trim($name);
        $encoded = preg_match('/[^\x20-\x7E]/', $name) === 1
            ? $this->encodeHeader($name)
            : '"'.addcslashes($name, '"\\').'"';

        return $encoded.' <'.$email.'>';
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function foldList(array $ids): string
    {
        return implode("\r\n ", $ids);
    }

    private function boundary(string $prefix): string
    {
        return '=_'.$prefix.'_'.bin2hex(random_bytes(12));
    }
}
