<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mapping;

use App\Modules\Sync\Enums\SyncEntity;

/**
 * Default-Mapping v1.0 gemäß docs/immoware/03-field-mapping.md (RFC-Standardfelder, keine
 * Immoware24-Spezifika). Jede Regel: source_field, target_field, transform.
 */
final class DefaultFieldMappings
{
    public const string SOURCE_SYSTEM = 'immoware24';

    /**
     * @return array<string, array{entity_type: string, source_format: string, mapping: array<int, array{source_field: string, target_field: string, transform: string|null}>, key_schema: array<string, mixed>, notes: string}>
     */
    public static function all(): array
    {
        return [
            'contact' => [
                'entity_type' => SyncEntity::Contact->value,
                'source_format' => SyncEntity::Contact->sourceFormat(),
                'mapping' => [
                    self::rule('UID', 'vcard_uid', 'trim'),
                    self::rule('href', 'vcard_href', 'trim'),
                    self::rule('REV', 'vcard_rev', 'trim'),
                    self::rule('N.family', 'last_name', 'trim'),
                    self::rule('N.given', 'first_name', 'trim'),
                    self::rule('N.prefix', 'salutation', 'trim'),
                    self::rule('FN', 'extra_properties.fn', 'trim'),
                    self::rule('BDAY', 'birth_date', 'date'),
                    self::rule('ORG', 'extra_properties.org', 'trim'),
                    self::rule('EMAIL', 'emails', 'list'),
                    self::rule('TEL', 'phones', 'phone_list'),
                    self::rule('ADR', 'addresses', 'list'),
                    self::rule('NOTE', 'extra_properties.note', null),
                    self::rule('CATEGORIES', 'extra_properties.categories', 'csv_list'),
                    self::rule('URL', 'extra_properties.urls', 'list'),
                    self::rule('KIND', 'kind', 'vcard_kind'),
                ],
                'key_schema' => ['external_id' => ['UID', 'href'], 'checksum_exclude' => ['REV', 'PRODID', 'VERSION', 'PHOTO']],
                'notes' => 'vCard 3.0/4.0 Standardfelder, 03-field-mapping.md Abschnitt 1. X-Properties in extra_properties.x.',
            ],
            'calendar_event' => [
                'entity_type' => SyncEntity::CalendarEvent->value,
                'source_format' => SyncEntity::CalendarEvent->sourceFormat(),
                'mapping' => [
                    self::rule('UID', 'ical_uid', 'trim'),
                    self::rule('RECURRENCE-ID', 'recurrence_id', 'trim'),
                    self::rule('href', 'ical_href', 'trim'),
                    self::rule('SUMMARY', 'summary', 'ical_unescape'),
                    self::rule('DESCRIPTION', 'description', 'ical_unescape'),
                    self::rule('LOCATION', 'location', 'ical_unescape'),
                    self::rule('DTSTART', 'starts_at', 'datetime'),
                    self::rule('DTEND', 'ends_at', 'datetime'),
                    self::rule('STATUS', 'status', 'upper'),
                    self::rule('RRULE', 'rrule', null),
                    self::rule('EXDATE', 'exdates', 'list'),
                    self::rule('ORGANIZER', 'organizer', 'mailto'),
                    self::rule('ATTENDEE', 'attendees', 'list'),
                    self::rule('CATEGORIES', 'categories', 'csv_list'),
                    self::rule('SEQUENCE', 'sequence', 'int'),
                    self::rule('DTSTAMP', 'dtstamp', 'datetime'),
                ],
                'key_schema' => ['external_id' => ['UID', 'href'], 'external_id_suffix' => 'RECURRENCE-ID', 'checksum_exclude' => ['DTSTAMP', 'LAST-MODIFIED', 'CREATED', 'SEQUENCE']],
                'notes' => 'iCalendar VEVENT Standardfelder, 03-field-mapping.md Abschnitt 2.',
            ],
            'document' => [
                'entity_type' => SyncEntity::Document->value,
                'source_format' => SyncEntity::Document->sourceFormat(),
                'mapping' => [
                    self::rule('href', 'path', 'dav_path'),
                    self::rule('displayname', 'filename', 'trim'),
                    self::rule('getcontentlength', 'size_bytes', 'int'),
                    self::rule('getcontenttype', 'mime_type', 'lower'),
                    self::rule('getlastmodified', 'remote_last_modified', 'datetime'),
                    self::rule('getetag', 'remote_etag', 'trim'),
                    self::rule('creationdate', 'remote_created_at', 'datetime'),
                    self::rule('resourcetype', 'is_collection', 'dav_is_collection'),
                ],
                'key_schema' => ['external_id' => ['href'], 'checksum_exclude' => ['getetag']],
                'notes' => 'WebDAV PROPFIND Properties, 03-field-mapping.md Abschnitt 3.',
            ],
        ];
    }

    /**
     * @return array{source_field: string, target_field: string, transform: string|null}
     */
    private static function rule(string $source, string $target, ?string $transform): array
    {
        return ['source_field' => $source, 'target_field' => $target, 'transform' => $transform];
    }
}
