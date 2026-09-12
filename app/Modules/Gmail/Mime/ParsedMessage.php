<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Mime;

use Carbon\CarbonImmutable;

/**
 * Ergebnis des MimeParser. Alle Texte in UTF-8, Adressen normalisiert (email klein, name optional).
 * parts ist die flache Liste aller MIME-Teile mit Pfad-IDs (1, 1.1, 1.2, 2), attachments und inline die Teilmengen.
 */
final class ParsedMessage
{
    /**
     * @param  array<string, array<int, string>>  $headers  Header-Name (klein) => rohe Werte (entfaltet)
     * @param  array<int, array{email: string, name: ?string}>  $from
     * @param  array<int, array{email: string, name: ?string}>  $to
     * @param  array<int, array{email: string, name: ?string}>  $cc
     * @param  array<int, array{email: string, name: ?string}>  $bcc
     * @param  array<int, array{email: string, name: ?string}>  $replyTo
     * @param  array<int, string>  $references
     * @param  array<int, array<string, mixed>>  $parts  part_id, parent_part_id, mime_type, filename, content_id, disposition, size_bytes, charset, content (nur Text und Anhänge), headers
     * @param  array<int, array<string, mixed>>  $attachments
     * @param  array<int, array<string, mixed>>  $inline
     */
    public function __construct(
        public readonly array $headers,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly array $replyTo,
        public readonly ?string $subject,
        public readonly ?CarbonImmutable $date,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly array $parts,
        public readonly array $attachments,
        public readonly array $inline,
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : $values[0];
    }

    /**
     * @return array{email: string, name: ?string}|null
     */
    public function sender(): ?array
    {
        return $this->from[0] ?? null;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }
}
