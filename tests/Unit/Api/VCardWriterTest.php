<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Modules\Api\Directory\DirectoryEntry;
use App\Modules\Api\Directory\VCardWriter;
use PHPUnit\Framework\TestCase;

final class VCardWriterTest extends TestCase
{
    public function test_long_lines_are_folded_and_special_characters_escaped(): void
    {
        $entry = new DirectoryEntry(
            id: 7,
            displayName: str_repeat('Ä', 60).'; Name, mit Komma',
            firstName: 'Max',
            lastName: 'Muster;Mann',
            company: null,
            phones: ['0211123'],
            mobiles: [],
            emails: ['max@example.test'],
            roles: [['role' => 'tenant', 'property_id' => 1, 'property' => 'Objekt A', 'unit_id' => 2, 'unit' => 'WE 1']],
            updatedAt: '2026-09-12T10:00:00.000Z',
        );

        $card = (new VCardWriter)->card($entry);
        $lines = explode("\r\n", rtrim($card));

        $this->assertSame('BEGIN:VCARD', $lines[0]);
        $this->assertSame('END:VCARD', $lines[array_key_last($lines)]);
        $this->assertStringContainsString('N:Muster\;Mann;Max;;;', $card);
        $this->assertStringContainsString('CATEGORIES:tenant Objekt A WE 1', $card);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(76, strlen($line));
        }

        // Entfaltet ergibt sich der vollständige FN-Wert.
        $unfolded = str_replace("\r\n ", '', $card);
        $this->assertStringContainsString('FN:'.str_repeat('Ä', 60).'\; Name\, mit Komma', $unfolded);
    }
}
