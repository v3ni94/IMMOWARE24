<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\DTO\CsvDocument;
use App\Modules\Imports\Exceptions\ImportException;
use Generator;
use SplFileObject;

/**
 * Streaming-CSV-Leser: erkennt Zeichensatz (UTF-8 mit und ohne BOM, Windows-1252 bzw. ISO-8859-1),
 * Trennzeichen (Semikolon, Komma, Tabulator) und liefert Zeilen als assoziative Arrays über die
 * normalisierten Spaltennamen. Die Datei wird nie vollständig in den Speicher geladen.
 */
final class CsvReader
{
    public const string ENCODING_UTF8 = 'UTF-8';

    public const string ENCODING_WINDOWS_1252 = 'Windows-1252';

    private const int SAMPLE_BYTES = 65536;

    /** @var array<int, string> */
    private const array DELIMITERS = [';', ',', "\t"];

    /**
     * @param  int  $headerLine  1-basierte Zeile der Spaltenüberschriften (DATEV: 2)
     */
    public function analyze(string $path, int $headerLine = 1): CsvDocument
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ImportException('CSV-Datei nicht lesbar.');
        }

        $sample = (string) file_get_contents($path, false, null, 0, self::SAMPLE_BYTES);
        $hasBom = str_starts_with($sample, "\xEF\xBB\xBF");
        $encoding = $this->detectEncoding($hasBom ? substr($sample, 3) : $sample);

        $rawHeaderLine = $this->headerLineRaw($path, $headerLine, $hasBom);

        if ($rawHeaderLine === null) {
            throw new ImportException('CSV-Datei enthält keine Kopfzeile.');
        }

        $delimiter = $this->detectDelimiter($rawHeaderLine);
        $headers = array_map(
            fn ($v): string => $this->toUtf8((string) $v, $encoding),
            str_getcsv(rtrim($rawHeaderLine, "\r\n"), $delimiter, '"', '\\'),
        );

        if ($headers !== [] && $hasBom) {
            $headers[0] = ltrim($headers[0], "\u{FEFF}");
        }

        $headers = array_map(static fn (string $h): string => trim($h), $headers);
        $normalized = HeaderNormalizer::normalizeAll($headers);

        return new CsvDocument(
            path: $path,
            encoding: $encoding,
            hasBom: $hasBom,
            delimiter: $delimiter,
            headers: $headers,
            normalizedHeaders: $normalized,
            fingerprint: HeaderNormalizer::fingerprint($normalized),
            headerLine: $headerLine,
        );
    }

    /**
     * Streamt die Datenzeilen als assoziative Arrays (normalisierter Header => getrimmter UTF-8-Wert).
     * Zusätzlich wird der Schlüssel "_line" mit der 1-basierten Zeilennummer gesetzt.
     *
     * @return Generator<int, array<string, string|int>>
     */
    public function rows(CsvDocument $document): Generator
    {
        $file = $this->openFile($document->path, $document->delimiter);
        $line = 0;
        $columns = count($document->normalizedHeaders);

        foreach ($file as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            $line++;
            if ($line <= $document->headerLine) {
                continue;
            }

            $values = array_map(fn ($v): string => trim($this->toUtf8((string) $v, $document->encoding)), $row);

            if (count($values) < $columns) {
                $values = array_pad($values, $columns, '');
            } elseif (count($values) > $columns) {
                $values = array_slice($values, 0, $columns);
            }

            /** @var array<string, string|int> $assoc */
            $assoc = array_combine($document->normalizedHeaders, $values);
            $assoc['_line'] = $line;

            yield $assoc;
        }
    }

    public function detectEncoding(string $sample): string
    {
        if ($sample === '' || mb_check_encoding($sample, 'UTF-8')) {
            return self::ENCODING_UTF8;
        }

        // Windows-1252 ist Obermenge von ISO-8859-1 im druckbaren Bereich; beides wird identisch behandelt.
        return self::ENCODING_WINDOWS_1252;
    }

    public function detectDelimiter(string $headerLine): string
    {
        $best = ';';
        $bestCount = -1;

        foreach (self::DELIMITERS as $delimiter) {
            $count = $this->countOutsideQuotes($headerLine, $delimiter);
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    public function toUtf8(string $value, string $encoding): string
    {
        if ($encoding === self::ENCODING_UTF8) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', $encoding);
    }

    private function openFile(string $path, string $delimiter = ';'): SplFileObject
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, '"', '\\');

        return $file;
    }

    private function headerLineRaw(string $path, int $headerLine, bool $hasBom): ?string
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $line = 0;
        $result = null;

        while (($raw = fgets($handle)) !== false) {
            if (trim($raw) === '') {
                continue;
            }
            $line++;
            if ($line === $headerLine) {
                $result = $raw;
                break;
            }
        }

        fclose($handle);

        if ($result !== null && $hasBom && $headerLine === 1) {
            $result = substr($result, 3);
        }

        return $result;
    }

    private function countOutsideQuotes(string $line, string $delimiter): int
    {
        $count = 0;
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                $inQuotes = ! $inQuotes;
            } elseif (! $inQuotes && $char === $delimiter) {
                $count++;
            }
        }

        return $count;
    }
}
