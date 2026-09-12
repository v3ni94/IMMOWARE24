<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Prüft Anhänge vor Ablage oder Weitergabe: Dateityp-Allowlist (MIME und Endung), Größe, blockierte Endungen und
 * MIME-Typen (Makros, ausführbare Inhalte, Archive), Inhaltskennzeichen (MZ-Header, ELF, Shebang, OOXML mit vbaProject,
 * OLE mit Makros) und SHA-256. Ergebnis clean, blocked oder skipped mit Grund. OCR ist nicht eingerichtet.
 */
final class AttachmentInspector
{
    public const string CLEAN = 'clean';

    public const string BLOCKED = 'blocked';

    public const string SKIPPED = 'skipped';

    public function __construct(private readonly Repository $config) {}

    /**
     * @return array{status: string, reason: ?string, sha256: ?string, size_bytes: int, extension: string, mime_type: string}
     */
    public function inspect(string $filename, string $mimeType, string $content): array
    {
        $size = strlen($content);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = strtolower(trim(explode(';', $mimeType)[0]));
        $sha = $size > 0 ? hash('sha256', $content) : null;
        $base = ['sha256' => $sha, 'size_bytes' => $size, 'extension' => $extension, 'mime_type' => $mime];

        $blockedExt = array_map('strval', (array) $this->config->get('hub.drive.attachments.blocked_extensions', []));
        $blockedMime = array_map('strval', (array) $this->config->get('hub.drive.attachments.blocked_mime_types', []));
        $allowedExt = array_map('strval', (array) $this->config->get('hub.drive.attachments.allowed_extensions', []));
        $allowedMime = array_map('strval', (array) $this->config->get('hub.drive.attachments.allowed_mime_types', []));
        $maxBytes = (int) $this->config->get('hub.drive.attachments.max_bytes', 25 * 1024 * 1024);

        // Doppelte Endung wie rechnung.pdf.exe: jede Teilendung zählt.
        foreach (array_slice(explode('.', strtolower($filename)), 1) as $part) {
            if (in_array($part, $blockedExt, true)) {
                return $base + ['status' => self::BLOCKED, 'reason' => sprintf('Blockierte Endung .%s', $part)];
            }
        }

        if (in_array($mime, $blockedMime, true)) {
            return $base + ['status' => self::BLOCKED, 'reason' => sprintf('Blockierter Typ %s', $mime)];
        }

        if ($contentReason = $this->inspectContent($content, $extension)) {
            return $base + ['status' => self::BLOCKED, 'reason' => $contentReason];
        }

        if ($size > $maxBytes) {
            return $base + ['status' => self::SKIPPED, 'reason' => sprintf('Größer als %d Byte', $maxBytes)];
        }

        if ($extension === '' || ! in_array($extension, $allowedExt, true)) {
            return $base + ['status' => self::SKIPPED, 'reason' => sprintf('Endung .%s nicht in Allowlist', $extension)];
        }

        if (! in_array($mime, $allowedMime, true)) {
            return $base + ['status' => self::SKIPPED, 'reason' => sprintf('Typ %s nicht in Allowlist', $mime)];
        }

        return $base + ['status' => self::CLEAN, 'reason' => null];
    }

    /**
     * OCR-Status für die Oberfläche.
     */
    public function ocrStatus(): string
    {
        $provider = $this->config->get('hub.drive.ocr.provider');

        return is_string($provider) && $provider !== '' ? 'configured' : 'not_configured';
    }

    private function inspectContent(string $content, string $extension): ?string
    {
        if ($content === '') {
            return null;
        }

        $head = substr($content, 0, 8);

        if (str_starts_with($head, 'MZ')) {
            return 'Ausführbarer Inhalt (PE-Header)';
        }

        if (str_starts_with($head, "\x7FELF")) {
            return 'Ausführbarer Inhalt (ELF-Header)';
        }

        if (str_starts_with($head, '#!')) {
            return 'Skript mit Shebang';
        }

        // OOXML (ZIP-Container): Office-Datei mit eingebettetem Makroprojekt.
        if (str_starts_with($head, "PK\x03\x04") && stripos($content, 'vbaProject.bin') !== false) {
            return 'Office-Dokument mit Makros (vbaProject.bin)';
        }

        // OLE2 (doc, xls, ppt): Makro-Verzeichnisse als UTF-16LE-Namen.
        if (str_starts_with($head, "\xD0\xCF\x11\xE0") && (str_contains($content, $this->utf16('VBA')) || str_contains($content, $this->utf16('_VBA_PROJECT')) || str_contains($content, $this->utf16('Macros')))) {
            return 'Office-Dokument mit Makros (OLE VBA)';
        }

        if ($extension === 'pdf' && (stripos($content, '/JavaScript') !== false || stripos($content, '/Launch') !== false)) {
            return 'PDF mit JavaScript oder Startaktion';
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'pdf'], true) && str_starts_with(ltrim($content), '<?php')) {
            return 'Skriptinhalt in Datei mit Dokumentendung';
        }

        return null;
    }

    private function utf16(string $ascii): string
    {
        return mb_convert_encoding($ascii, 'UTF-16LE', 'ASCII');
    }
}
