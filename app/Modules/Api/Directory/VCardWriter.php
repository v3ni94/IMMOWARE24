<?php

declare(strict_types=1);

namespace App\Modules\Api\Directory;

/**
 * Schreibt Verzeichniseinträge als vCard 3.0 (RFC 2426) mit Zeilenfaltung nach 75 Oktetten.
 */
final class VCardWriter
{
    /**
     * @param  iterable<DirectoryEntry>  $entries
     */
    public function write(iterable $entries): string
    {
        $out = '';

        foreach ($entries as $entry) {
            $out .= $this->card($entry);
        }

        return $out;
    }

    public function card(DirectoryEntry $entry): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:hub-contact-'.$entry->id,
            'FN:'.$this->escape($entry->displayName),
            'N:'.$this->escape($entry->lastName ?? '').';'.$this->escape($entry->firstName ?? '').';;;',
        ];

        if ($entry->company !== null) {
            $lines[] = 'ORG:'.$this->escape($entry->company);
        }

        foreach ($entry->phones as $phone) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:'.$this->escape($phone);
        }

        foreach ($entry->mobiles as $mobile) {
            $lines[] = 'TEL;TYPE=CELL:'.$this->escape($mobile);
        }

        foreach ($entry->emails as $email) {
            $lines[] = 'EMAIL;TYPE=INTERNET:'.$this->escape($email);
        }

        $categories = [];

        foreach ($entry->roles as $role) {
            $parts = array_filter([$role['role'], $role['property'], $role['unit']], static fn ($v) => is_string($v) && $v !== '');
            $categories[] = implode(' ', $parts);
        }

        if ($categories !== []) {
            $lines[] = 'CATEGORIES:'.implode(',', array_map($this->escape(...), array_unique($categories)));
        }

        if ($entry->updatedAt !== null) {
            $lines[] = 'REV:'.$entry->updatedAt;
        }

        $lines[] = 'END:VCARD';

        return implode('', array_map(fn (string $line): string => $this->fold($line)."\r\n", $lines));
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }

    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $first = true;

        foreach ($this->chunkUtf8($line, 75, 74) as $chunk) {
            $out .= ($first ? '' : "\r\n ").$chunk;
            $first = false;
        }

        return $out;
    }

    /**
     * Teilt eine UTF-8-Zeichenkette in Oktett-begrenzte Stücke, ohne Mehrbyte-Zeichen zu zerschneiden.
     *
     * @return array<int, string>
     */
    private function chunkUtf8(string $value, int $firstMax, int $nextMax): array
    {
        $chunks = [];
        $current = '';
        $max = $firstMax;

        foreach (mb_str_split($value) as $char) {
            if (strlen($current) + strlen($char) > $max) {
                $chunks[] = $current;
                $current = '';
                $max = $nextMax;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
