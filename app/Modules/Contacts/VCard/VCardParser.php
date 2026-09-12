<?php

declare(strict_types=1);

namespace App\Modules\Contacts\VCard;

/**
 * Eigener vCard-Parser für Version 3.0 und 4.0 (RFC 2426, RFC 6350), tolerant gegenüber 2.1-Schreibweisen.
 * Nur Lesen; ein Serializer existiert absichtlich nicht (CardDAV-Schreibpfad ist hart gesperrt).
 */
final class VCardParser
{
    private const array KNOWN = ['BEGIN', 'END', 'VERSION', 'PRODID', 'UID', 'FN', 'N', 'ORG', 'TITLE', 'EMAIL', 'TEL', 'ADR', 'NOTE', 'CATEGORIES', 'REV', 'LABEL', 'PHOTO', 'LOGO', 'SOUND', 'KEY'];

    public function __construct(private readonly ContentLineReader $reader = new ContentLineReader) {}

    /**
     * Liefert die erste vCard des Textes oder null, wenn keine enthalten ist.
     */
    public function parse(string $text): ?VCard
    {
        $all = $this->parseAll($text);

        return $all[0] ?? null;
    }

    /**
     * Alle vCards eines Textes (z. B. mehrere BEGIN:VCARD-Blöcke in einer Datei).
     *
     * @return array<int, VCard>
     */
    public function parseAll(string $text): array
    {
        $cards = [];
        $current = null;

        foreach ($this->reader->read($text) as $line) {
            if ($line->name === 'BEGIN' && strtoupper($line->rawValue) === 'VCARD') {
                $current = [];

                continue;
            }

            if ($line->name === 'END' && strtoupper($line->rawValue) === 'VCARD') {
                if ($current !== null) {
                    $cards[] = $this->build($current);
                }
                $current = null;

                continue;
            }

            if ($current !== null) {
                $current[] = $line;
            }
        }

        return $cards;
    }

    /**
     * @param  array<int, ContentLine>  $lines
     */
    private function build(array $lines): VCard
    {
        $version = '3.0';
        $uid = null;
        $fn = null;
        $name = null;
        $org = null;
        $orgUnit = null;
        $title = null;
        $emails = [];
        $phones = [];
        $addresses = [];
        $note = null;
        $categories = [];
        $rev = null;
        $extra = [];

        foreach ($lines as $line) {
            switch ($line->name) {
                case 'VERSION':
                    $version = trim($line->rawValue);
                    break;
                case 'UID':
                    $uid = $this->nullIfEmpty($this->stripUrnUuid(trim($line->value())));
                    break;
                case 'FN':
                    $fn ??= $this->nullIfEmpty(trim($line->value()));
                    break;
                case 'N':
                    $parts = array_pad($line->components(), 5, '');
                    $name = [
                        'family' => trim($parts[0]),
                        'given' => trim($parts[1]),
                        'additional' => trim($parts[2]),
                        'prefix' => trim($parts[3]),
                        'suffix' => trim($parts[4]),
                    ];
                    break;
                case 'ORG':
                    $parts = $line->components();
                    $org = $this->nullIfEmpty(trim($parts[0] ?? ''));
                    $orgUnit = $this->nullIfEmpty(trim(implode(' ', array_slice($parts, 1))));
                    break;
                case 'TITLE':
                    $title ??= $this->nullIfEmpty(trim($line->value()));
                    break;
                case 'EMAIL':
                    $value = trim($line->value());
                    if (str_starts_with(strtolower($value), 'mailto:')) {
                        $value = substr($value, 7);
                    }
                    if ($value !== '') {
                        $emails[] = ['value' => $value, 'types' => $this->typesOf($line)];
                    }
                    break;
                case 'TEL':
                    $value = trim($line->value());
                    if (str_starts_with(strtolower($value), 'tel:')) {
                        $value = substr($value, 4);
                    }
                    if ($value !== '') {
                        $phones[] = ['value' => $value, 'types' => $this->typesOf($line)];
                    }
                    break;
                case 'ADR':
                    $parts = array_pad($line->components(), 7, '');
                    $addresses[] = [
                        'types' => $this->typesOf($line),
                        'po_box' => trim($parts[0]),
                        'extended' => trim($parts[1]),
                        'street' => trim($parts[2]),
                        'city' => trim($parts[3]),
                        'region' => trim($parts[4]),
                        'postal_code' => trim($parts[5]),
                        'country' => trim($parts[6]),
                    ];
                    break;
                case 'NOTE':
                    $text = trim($line->value());
                    if ($text !== '') {
                        $note = $note === null ? $text : $note."\n".$text;
                    }
                    break;
                case 'CATEGORIES':
                    $categories = [...$categories, ...$line->list()];
                    break;
                case 'REV':
                    $rev = $this->nullIfEmpty(trim($line->value()));
                    break;
                case 'PRODID':
                case 'PHOTO':
                case 'LOGO':
                case 'SOUND':
                case 'KEY':
                case 'LABEL':
                    // bewusst nicht gespiegelt (kein Fachbezug bzw. Binärdaten)
                    break;
                default:
                    if (! in_array($line->name, self::KNOWN, true)) {
                        $extra[$line->name][] = $line->binary ? '[binary]' : $line->value();
                    }
            }
        }

        return new VCard(
            version: $version,
            uid: $uid,
            formattedName: $fn,
            name: $name,
            organization: $org,
            organizationUnit: $orgUnit,
            title: $title,
            emails: $emails,
            phones: $phones,
            addresses: $addresses,
            note: $note,
            categories: array_values(array_unique($categories)),
            rev: $rev,
            extra: $extra,
        );
    }

    /**
     * @return array<int, string>
     */
    private function typesOf(ContentLine $line): array
    {
        return array_values(array_filter($line->types(), static fn (string $t): bool => ! in_array($t, ['internet', 'x400'], true)));
    }

    private function stripUrnUuid(string $uid): string
    {
        return str_starts_with(strtolower($uid), 'urn:uuid:') ? substr($uid, 9) : $uid;
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
