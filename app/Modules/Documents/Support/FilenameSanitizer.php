<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Illuminate\Support\Str;

/**
 * Dateinamen für den Posteingang-Upload gemäß 05-write-capabilities.md Abschnitt 3.2:
 * Transliteration nach ASCII, erlaubt a bis z, A bis Z, 0 bis 9, Punkt, Bindestrich, Unterstrich,
 * keine Pfadtrenner, keine führenden Punkte, Mehrfach-Unterstriche reduziert, Nutzanteil maximal
 * 120 Zeichen, Gesamtlänge maximal 160 Zeichen inklusive UUID-Suffix und Endung.
 */
final class FilenameSanitizer
{
    public const int MAX_STEM_LENGTH = 120;

    public const int MAX_TOTAL_LENGTH = 160;

    public const string FALLBACK_EXTENSION = 'bin';

    /**
     * @param  array<int, string>  $allowedExtensions
     */
    public function __construct(private readonly array $allowedExtensions = []) {}

    /**
     * Liefert "<stem>_<suffix>.<ext>". Der Suffix ist der je Operation erzeugte UUID.
     */
    public function sanitize(string $originalFilename, string $suffix): string
    {
        $basename = WebDavPath::basename(str_replace('\\', '/', $originalFilename));
        $basename = $basename === '' ? 'dokument' : $basename;

        $extension = $this->extension($basename);
        $stem = $basename;

        // Die Originalendung wird immer vom Nutzanteil getrennt, auch wenn sie nicht zulässig ist (dann .bin).
        if (preg_match('/^(.*)\.([A-Za-z0-9]{1,20})$/', $basename, $m) === 1 && $m[1] !== '') {
            $stem = $m[1];
        }

        $stem = $this->sanitizeStem($stem);
        $suffix = $this->sanitizeStem($suffix);
        $extension ??= self::FALLBACK_EXTENSION;

        $stem = mb_substr($stem, 0, self::MAX_STEM_LENGTH);
        $result = $stem.'_'.$suffix.'.'.$extension;

        if (strlen($result) > self::MAX_TOTAL_LENGTH) {
            $allowedStem = max(1, self::MAX_TOTAL_LENGTH - strlen('_'.$suffix.'.'.$extension));
            $result = substr($stem, 0, $allowedStem).'_'.$suffix.'.'.$extension;
        }

        return $result;
    }

    public function sanitizeStem(string $stem): string
    {
        $ascii = Str::ascii($stem, 'de');
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $ascii);
        $ascii = (string) preg_replace('/_{2,}/', '_', $ascii);
        $ascii = ltrim($ascii, '._-');
        $ascii = rtrim($ascii, '_');

        return $ascii === '' ? 'dokument' : $ascii;
    }

    /**
     * Endung nur, wenn sie aus a bis z und 0 bis 9 besteht, maximal 8 Zeichen hat und, falls eine
     * Allowlist konfiguriert ist, darin enthalten ist.
     */
    public function extension(string $filename): ?string
    {
        $pos = strrpos($filename, '.');

        if ($pos === false || $pos === strlen($filename) - 1) {
            return null;
        }

        $extension = strtolower(substr($filename, $pos + 1));

        if (preg_match('/^[a-z0-9]{1,8}$/', $extension) !== 1) {
            return null;
        }

        if ($this->allowedExtensions !== [] && ! in_array($extension, $this->allowedExtensions, true)) {
            return null;
        }

        return $extension;
    }
}
