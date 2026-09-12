<?php

declare(strict_types=1);

namespace Tests\Unit\Drive;

use App\Modules\Drive\Services\AttachmentInspector;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class AttachmentInspectorTest extends TestCase
{
    private AttachmentInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var array<string, mixed> $drive */
        $drive = require dirname(__DIR__, 3).'/config/hub/drive.php';
        $drive['attachments']['max_bytes'] = 1000;
        $this->inspector = new AttachmentInspector(new Repository(['hub' => ['drive' => $drive]]));
    }

    public function test_clean_pdf_has_hash_and_size(): void
    {
        $result = $this->inspector->inspect('Rechnung.PDF', 'application/pdf; charset=binary', '%PDF-1.7 inhalt');

        $this->assertSame('clean', $result['status']);
        $this->assertSame(hash('sha256', '%PDF-1.7 inhalt'), $result['sha256']);
        $this->assertSame(15, $result['size_bytes']);
        $this->assertSame('pdf', $result['extension']);
    }

    public function test_macro_and_executable_content_is_blocked(): void
    {
        $this->assertSame('blocked', $this->inspector->inspect('makro.docm', 'application/octet-stream', 'x')['status']);
        $this->assertSame('blocked', $this->inspector->inspect('rechnung.pdf.exe', 'application/pdf', 'x')['status']);
        $this->assertSame('blocked', $this->inspector->inspect('archiv.zip', 'application/zip', 'x')['status']);
        $this->assertSame('Ausführbarer Inhalt (PE-Header)', $this->inspector->inspect('bild.png', 'image/png', "MZ\x90\x00rest")['reason']);
        $this->assertSame('Office-Dokument mit Makros (vbaProject.bin)', $this->inspector->inspect('brief.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04...word/vbaProject.bin...")['reason']);
        $this->assertSame('Office-Dokument mit Makros (OLE VBA)', $this->inspector->inspect('alt.doc', 'application/msword', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".mb_convert_encoding('_VBA_PROJECT', 'UTF-16LE', 'ASCII'))['reason']);
        $this->assertSame('PDF mit JavaScript oder Startaktion', $this->inspector->inspect('f.pdf', 'application/pdf', '%PDF-1.4 /OpenAction << /S /JavaScript >>')['reason']);
        $this->assertSame('Skript mit Shebang', $this->inspector->inspect('notiz.txt', 'text/plain', "#!/bin/sh\nrm -rf /")['reason']);
    }

    public function test_size_and_allowlist_lead_to_skipped(): void
    {
        $this->assertSame('skipped', $this->inspector->inspect('gross.pdf', 'application/pdf', str_repeat('a', 1001))['status']);
        $this->assertSame('skipped', $this->inspector->inspect('cad.dwg', 'application/acad', 'x')['status']);
        $this->assertSame('skipped', $this->inspector->inspect('bild.png', 'application/octet-stream', 'x')['status']);
        $this->assertSame('not_configured', $this->inspector->ocrStatus());
    }
}
