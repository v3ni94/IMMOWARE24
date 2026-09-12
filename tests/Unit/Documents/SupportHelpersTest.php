<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Support\DocumentTypeClassifier;
use App\Modules\Documents\Support\FilenameSanitizer;
use App\Modules\Documents\Support\WebDavPath;
use PHPUnit\Framework\TestCase;

final class SupportHelpersTest extends TestCase
{
    public function test_path_normalization_resolves_dots_encoding_and_nfc(): void
    {
        $this->assertSame('/Posteingang/', WebDavPath::normalize('Posteingang//', true));
        $this->assertSame('/Posteingang/a.pdf', WebDavPath::normalize('/Posteingang/./x/../a.pdf'));
        $this->assertSame('/Dokumente/Müller.pdf', WebDavPath::normalize('/Dokumente/M%C3%BCller.pdf'));
        $this->assertSame('/', WebDavPath::normalize('/../..'));
        $this->assertSame('/Posteingang/', WebDavPath::fromHref('/share/Posteingang/', 'https://dav.example.test/share'));
        $this->assertSame('/', WebDavPath::fromHref('/share/', 'https://dav.example.test/share'));
        $this->assertSame('/Posteingang/', WebDavPath::parent('/Posteingang/a.pdf'));
        $this->assertSame(2, WebDavPath::depth('/Dokumente/0123 Objekt/'));
        $this->assertSame(['Dokumente', '0123 Objekt'], WebDavPath::folderSegments('/Dokumente/0123 Objekt/re.pdf'));
        $this->assertSame('/Posteingang/M%C3%BCller%20%26%20Co.pdf', WebDavPath::encode('/Posteingang/Müller & Co.pdf'));

        // Dekomponiertes ü (u + Kombinationszeichen) wird auf NFC normalisiert.
        $this->assertSame('/Dokumente/Müller.pdf', WebDavPath::normalize("/Dokumente/Mu\u{0308}ller.pdf"));
    }

    public function test_is_within_prefix_rejects_traversal_and_siblings(): void
    {
        $this->assertTrue(WebDavPath::isWithin('/Posteingang/a.pdf', '/Posteingang/'));
        $this->assertFalse(WebDavPath::isWithin('/Posteingang/', '/Posteingang/'));
        $this->assertFalse(WebDavPath::isWithin('/Dokumente/a.pdf', '/Posteingang/'));
        $this->assertFalse(WebDavPath::isWithin('/Posteingang/../Dokumente/a.pdf', '/Posteingang/'));
        $this->assertFalse(WebDavPath::isWithin('/PosteingangX/a.pdf', '/Posteingang/'));
    }

    public function test_filename_sanitizer_transliterates_and_limits_length(): void
    {
        $sanitizer = new FilenameSanitizer(['pdf', 'png']);

        $this->assertSame('Rechnung_Mueller_Soehne_2026_abc123.pdf', $sanitizer->sanitize('../Rechnung Müller & Söhne (2026).PDF', 'abc123'));
        $this->assertSame('dokument_abc.bin', $sanitizer->sanitize('...', 'abc'));
        $this->assertSame('script_abc.bin', $sanitizer->sanitize('script.exe', 'abc'));
        $this->assertSame('a_abc.bin', $sanitizer->sanitize('a.verylongextension', 'abc'));

        $long = str_repeat('x', 300).'.pdf';
        $result = $sanitizer->sanitize($long, 'suffix');
        $this->assertLessThanOrEqual(FilenameSanitizer::MAX_TOTAL_LENGTH, strlen($result));
        $this->assertStringEndsWith('_suffix.pdf', $result);
        $this->assertStringNotContainsString('/', $result);
    }

    public function test_type_classifier_uses_first_matching_rule(): void
    {
        $classifier = new DocumentTypeClassifier([
            ['type' => 'invoice', 'pattern' => '/rechnung/iu'],
            ['type' => 'protocol', 'pattern' => '/protokoll/iu'],
        ]);

        $this->assertSame(['type' => 'invoice', 'rule' => 'filename:invoice'], $classifier->classify('Rechnung_2026.pdf', '/Dokumente/'));
        $this->assertSame(['type' => 'protocol', 'rule' => 'folder:protocol'], $classifier->classify('scan1.pdf', '/Dokumente/Protokolle/'));
        $this->assertSame(['type' => null, 'rule' => null], $classifier->classify('foto.jpg', '/Dokumente/'));
    }
}
