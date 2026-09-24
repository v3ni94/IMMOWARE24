<?php

declare(strict_types=1);

namespace App\Core\Contracts\Mail;

/**
 * Interner Adaptervertrag für die Dokumentenablage Paperless-ngx. Anders als DocumentSourceInterface (Google Drive,
 * nur lesend) unterstützt dieser Vertrag zusätzlich das Anlegen neuer Dokumente (immer neues Dokument, kein
 * Überschreiben, kein Löschen über den Hub).
 */
interface PaperlessSourceInterface
{
    /**
     * @param  array<string, mixed>  $options  z. B. ['page' => 1, 'page_size' => 25, 'company' => 'HVM']
     * @return array{documents: array<int, array{id: int, title: string, correspondent: ?string, document_type: ?string, created: ?string, tags: array<int, string>, object_number: ?string, company: ?string}>, count: int, next: bool}
     */
    public function search(string $query, array $options = []): array;

    /**
     * @return array{id: int, title: string, correspondent: ?string, document_type: ?string, created: ?string, tags: array<int, string>, object_number: ?string, company: ?string, content_excerpt: ?string}
     */
    public function get(int $documentId): array;

    /**
     * Dokumente, deren Objektnummer-Zusatzfeld dem übergebenen Wert entspricht, optional zusätzlich nach
     * Gesellschaft eingeschränkt (Label wie in company_options, z. B. "HVM").
     *
     * @param  array<string, mixed>  $options  z. B. ['page' => 1, 'page_size' => 25, 'company' => 'HVM']
     * @return array{documents: array<int, array{id: int, title: string, correspondent: ?string, document_type: ?string, created: ?string, tags: array<int, string>, object_number: ?string, company: ?string}>, count: int, next: bool}
     */
    public function forProperty(string $objectNumber, array $options = []): array;

    /**
     * Legt ein neues Dokument an (create-only, kein Überschreiben eines bestehenden Dokuments). Setzt das
     * Objektnummer- und Gesellschafts-Zusatzfeld, sofern konfiguriert und übergeben (Gesellschaft als Label,
     * z. B. "HVM", wird auf die passende Options-ID abgebildet). Liefert die von Paperless vergebene Task-ID
     * (asynchrone Verarbeitung, kein sofortiges Dokument-Ergebnis).
     */
    public function upload(string $filename, string $content, string $mimeType, string $title, ?string $objectNumber = null, ?string $company = null): string;
}
