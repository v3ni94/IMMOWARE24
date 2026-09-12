<?php

declare(strict_types=1);

namespace App\Core\Contracts;

interface FieldMapperInterface
{
    /**
     * Entitätstyp, den dieser Mapper bedient (z. B. contact, document, unit).
     */
    public function entityType(): string;

    /**
     * Version des Mappings (field_mappings.version), die für Reproduzierbarkeit protokolliert wird.
     */
    public function version(): int;

    /**
     * Übersetzt eine externe Nutzlast (vCard, iCalendar, CSV-Zeile, PROPFIND-Eintrag) in normalisierte Hub-Attribute.
     *
     * @param  array<string, mixed>  $external
     * @return array<string, mixed>
     */
    public function toLocal(array $external): array;

    /**
     * Übersetzt Hub-Attribute in das externe Format (nur für den create-only Schreibpfad relevant).
     *
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    public function toExternal(array $local): array;

    /**
     * Liefert die externe ID eines Datensatzes; Zuordnung nie über Namen oder E-Mail.
     *
     * @param  array<string, mixed>  $external
     */
    public function externalId(array $external): string;
}
