<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Mime;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Eigener MIME-Parser (RFC 2822/5322 Header, RFC 2045/2046 Multipart rekursiv, Content-Transfer-Encoding base64 und
 * quoted-printable, Zeichensätze über HeaderDecoder, RFC 2047 Header, RFC 2231 Dateinamen, Content-ID für Inline-Bilder).
 * Tolerant gegenüber fehlerhaften Nachrichten: Ein Parsefehler liefert nie einen leeren Erfolg, sondern die rohen
 * Header und den Körper als text/plain.
 */
final class MimeParser
{
    public function __construct(private readonly HeaderDecoder $headers = new HeaderDecoder) {}

    /**
     * @param  string  $raw  Vollständige MIME-Nachricht (bereits base64url-dekodiert).
     */
    public function parse(string $raw): ParsedMessage
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock, $body] = $this->splitHeadersAndBody($raw);
        $headers = $this->parseHeaderBlock($headerBlock);

        $parts = [];
        $this->parsePart($headers, $body, '', null, $parts, 0);

        $text = null;
        $html = null;
        $attachments = [];
        $inline = [];

        foreach ($parts as $part) {
            $type = (string) $part['mime_type'];
            $disposition = $part['disposition'];
            $isAttachmentLike = $disposition === 'attachment' || ($part['filename'] !== null && $disposition !== 'inline' && ! in_array($type, ['text/plain', 'text/html'], true));

            if ($isAttachmentLike) {
                $attachments[] = $part;

                continue;
            }

            if ($part['content_id'] !== null && $disposition === 'inline' || ($part['content_id'] !== null && str_starts_with($type, 'image/'))) {
                $inline[] = $part;

                continue;
            }

            if ($disposition === 'inline' && $part['filename'] !== null && ! in_array($type, ['text/plain', 'text/html'], true)) {
                $attachments[] = $part;

                continue;
            }

            if ($type === 'text/plain' && $text === null && ! $part['is_container']) {
                $text = (string) $part['content'];
            } elseif ($type === 'text/html' && $html === null && ! $part['is_container']) {
                $html = (string) $part['content'];
            } elseif (! $part['is_container'] && ! str_starts_with($type, 'text/') && $part['content'] !== null && $part['content'] !== '') {
                // Unbenannter Binärteil ohne Disposition: als Anhang führen, nie verwerfen.
                $part['filename'] = $part['filename'] ?? 'anhang-'.str_replace('.', '-', (string) $part['part_id']).'.bin';
                $attachments[] = $part;
            }
        }

        $messageIds = $this->headers->parseMessageIds($this->first($headers, 'message-id') ?? '');
        $inReplyTo = $this->headers->parseMessageIds($this->first($headers, 'in-reply-to') ?? '');
        $references = $this->headers->parseMessageIds($this->first($headers, 'references') ?? '');

        return new ParsedMessage(
            headers: $headers,
            messageId: $messageIds[0] ?? null,
            inReplyTo: $inReplyTo[0] ?? null,
            references: $references,
            from: $this->addresses($headers, 'from'),
            to: $this->addresses($headers, 'to'),
            cc: $this->addresses($headers, 'cc'),
            bcc: $this->addresses($headers, 'bcc'),
            replyTo: $this->addresses($headers, 'reply-to'),
            subject: $this->first($headers, 'subject') !== null ? $this->headers->decodeText((string) $this->first($headers, 'subject')) : null,
            date: $this->parseDate($this->first($headers, 'date')),
            text: $text,
            html: $html,
            parts: array_map(static function (array $part): array {
                unset($part['is_container']);

                return $part;
            }, $parts),
            attachments: $attachments,
            inline: $inline,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitHeadersAndBody(string $raw): array
    {
        $position = strpos($raw, "\n\n");

        if ($position === false) {
            // Nur Header oder nur Körper: beginnt die erste Zeile nicht wie ein Header, ist alles Körper.
            return preg_match('/^[!-9;-~]+:/', $raw) === 1 ? [$raw, ''] : ['', $raw];
        }

        return [substr($raw, 0, $position), substr($raw, $position + 2)];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function parseHeaderBlock(string $block): array
    {
        $headers = [];
        $lines = explode("\n", $block);
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $index = count($headers[$current]) - 1;
                $headers[$current][$index] .= ' '.trim($line);

                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $current = strtolower(trim(substr($line, 0, $colon)));
            $headers[$current][] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @param  array<int, array<string, mixed>>  $parts
     */
    private function parsePart(array $headers, string $body, string $prefix, ?string $parentId, array &$parts, int $depth): void
    {
        $contentType = $this->headers->parseParameterized($this->first($headers, 'content-type') ?? 'text/plain; charset=us-ascii');
        $type = $contentType['value'] !== '' ? $contentType['value'] : 'text/plain';
        $disposition = $this->first($headers, 'content-disposition') !== null
            ? $this->headers->parseParameterized((string) $this->first($headers, 'content-disposition'))
            : ['value' => null, 'params' => []];
        $partId = $prefix === '' ? '1' : $prefix;
        $encoding = strtolower(trim((string) ($this->first($headers, 'content-transfer-encoding') ?? '7bit')));
        $contentId = $this->first($headers, 'content-id');
        $contentId = $contentId !== null ? trim($contentId, " <>\t") : null;
        $filename = $disposition['params']['filename'] ?? $contentType['params']['name'] ?? null;
        $filename = $filename !== null ? $this->sanitizeFilename($filename) : null;
        $boundary = $contentType['params']['boundary'] ?? null;
        $isMultipart = str_starts_with($type, 'multipart/') && $boundary !== null && $depth < 20;

        $partRecord = [
            'part_id' => $partId,
            'parent_part_id' => $parentId,
            'mime_type' => $type,
            'filename' => $filename,
            'content_id' => $contentId !== '' ? $contentId : null,
            'disposition' => is_string($disposition['value']) && $disposition['value'] !== '' ? $disposition['value'] : null,
            'charset' => $contentType['params']['charset'] ?? null,
            'size_bytes' => strlen($body),
            'headers' => $this->flattenHeaders($headers),
            'content' => null,
            'is_container' => $isMultipart,
        ];

        if ($isMultipart) {
            $parts[] = $partRecord;

            foreach ($this->splitMultipart($body, (string) $boundary) as $index => $section) {
                [$childHeaderBlock, $childBody] = $this->splitHeadersAndBody($section);
                $childHeaders = $this->parseHeaderBlock($childHeaderBlock);
                $this->parsePart($childHeaders, $childBody, $partId.'.'.($index + 1), $partId, $parts, $depth + 1);
            }

            return;
        }

        if ($type === 'message/rfc822' && $depth < 20) {
            // Weitergeleitete Nachricht: Header und Körper als Anhang (eml) führen, nicht in den Text ziehen.
            $partRecord['filename'] = $filename ?? 'weitergeleitete-nachricht.eml';
            $partRecord['disposition'] = $partRecord['disposition'] ?? 'attachment';
            $partRecord['content'] = $this->decodeBody($body, $encoding);
            $partRecord['size_bytes'] = strlen((string) $partRecord['content']);
            $parts[] = $partRecord;

            return;
        }

        $decoded = $this->decodeBody($body, $encoding);

        if (str_starts_with($type, 'text/')) {
            $decoded = $this->headers->toUtf8($decoded, (string) ($contentType['params']['charset'] ?? 'us-ascii'));
            $decoded = str_replace("\r\n", "\n", $decoded);
        }

        $partRecord['content'] = $decoded;
        $partRecord['size_bytes'] = strlen($decoded);
        $parts[] = $partRecord;
    }

    /**
     * @return array<int, string>
     */
    private function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--'.$boundary;
        $sections = [];
        $lines = explode("\n", $body);
        $current = null;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if ($trimmed === $delimiter) {
                if ($current !== null) {
                    $sections[] = implode("\n", $current);
                }

                $current = [];

                continue;
            }

            if ($trimmed === $delimiter.'--') {
                if ($current !== null) {
                    $sections[] = implode("\n", $current);
                }

                $current = null;

                break;
            }

            if ($current !== null) {
                $current[] = $line;
            }
        }

        if ($current !== null) {
            $sections[] = implode("\n", $current);
        }

        // Letzte Leerzeile vor dem Trenner gehört zum Delimiter (RFC 2046), nicht zum Inhalt.
        return array_map(static fn (string $section): string => str_ends_with($section, "\n") ? substr($section, 0, -1) : $section, $sections);
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function first(array $headers, string $name): ?string
    {
        return $headers[$name][0] ?? null;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<int, array{email: string, name: ?string}>
     */
    private function addresses(array $headers, string $name): array
    {
        $result = [];

        foreach ($headers[$name] ?? [] as $value) {
            foreach ($this->headers->parseAddressList($value) as $address) {
                $result[] = $address;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $result = [];

        foreach (['content-type', 'content-disposition', 'content-transfer-encoding', 'content-id', 'content-description'] as $name) {
            if (isset($headers[$name][0])) {
                $result[$name] = mb_substr($this->headers->ensureUtf8($headers[$name][0]), 0, 500);
            }
        }

        return $result;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Kommentare in Klammern wie "(CEST)" und den Wochentag entfernen (falsche Wochentage sind verbreitet).
        $clean = trim((string) preg_replace('/\([^)]*\)/', '', $value));
        $clean = trim((string) preg_replace('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*,\s*/i', '', $clean));

        try {
            return CarbonImmutable::parse($clean)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(['\\', '/', "\0"], '_', trim($filename));
        $filename = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        $filename = ltrim($filename, '.');

        return $filename !== '' ? mb_substr($filename, 0, 255) : 'anhang';
    }
}
