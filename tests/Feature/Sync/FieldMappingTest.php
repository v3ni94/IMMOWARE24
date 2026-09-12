<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\Contracts\FieldMapperInterface;
use App\Core\Enums\Role;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Models\FieldMapping;
use App\Modules\Sync\Services\FieldMappingService;

final class FieldMappingTest extends SyncTestCase
{
    public function test_seed_defaults_creates_version_one_for_vcard_ical_webdav(): void
    {
        $service = $this->app->make(FieldMappingService::class);
        $created = $service->seedDefaults();

        $this->assertCount(3, $created);
        $this->assertSame(['ical', 'vcard', 'webdav'], FieldMapping::query()->orderBy('source_format')->pluck('source_format')->all());
        $this->assertSame([1, 1, 1], FieldMapping::query()->pluck('version')->all());
        $this->assertSame(3, FieldMapping::query()->active()->count());

        $this->assertCount(0, $service->seedDefaults(), 'Seeder ist idempotent.');
    }

    public function test_change_creates_new_version_retires_old_and_writes_audit(): void
    {
        $user = $this->actingAsRole(Role::Administrator);
        $service = $this->app->make(FieldMappingService::class);
        $service->seedDefaults();

        $rules = $service->active('contact', 'vcard')?->getAttribute('mapping');
        $this->assertIsArray($rules);
        $rules[] = ['source_field' => 'X-DEBITOR', 'target_field' => 'extra_properties.debitor_number', 'transform' => 'trim'];

        $unchanged = $service->publish('contact', 'vcard', $service->active('contact', 'vcard')->getAttribute('mapping'), $user);
        $this->assertSame(1, $unchanged->getAttribute('version'), 'Unveränderte Regeln erzeugen keine neue Version.');

        $v2 = $service->publish('contact', 'vcard', $rules, $user, 'Debitorennummer ergänzt');

        $this->assertSame(2, $v2->getAttribute('version'));
        $this->assertSame('active', $v2->getAttribute('status'));
        $this->assertSame((int) $user->getKey(), (int) $v2->getAttribute('activated_by'));
        $v1 = FieldMapping::query()->where('source_format', 'vcard')->where('version', 1)->firstOrFail();
        $this->assertSame('retired', $v1->getAttribute('status'));
        $this->assertNotNull($v1->getAttribute('retired_at'));
        $this->assertSame((int) $v1->getKey(), (int) $v2->getAttribute('previous_version_id'));
        $this->assertSame(2, $service->mapper('contact', 'vcard')->version());
        $this->assertTrue(AuditLog::query()->where('action', 'field_mapping.published')->where('entity_id', $v2->getKey())->exists());
    }

    public function test_mapper_translates_vcard_payload(): void
    {
        $service = $this->app->make(FieldMappingService::class);
        $service->seedDefaults();
        $mapper = $service->mapper('contact', 'vcard');

        $this->assertInstanceOf(FieldMapperInterface::class, $mapper);
        $this->assertSame('contact', $mapper->entityType());

        $local = $mapper->toLocal([
            'UID' => ' uid-123 ',
            'N' => ['family' => 'Muster ', 'given' => ' Max'],
            'TEL' => [['type' => 'cell', 'value' => 'tel:+49 (0)211 123-456', 'pref' => true]],
            'CATEGORIES' => 'Mieter, Eigentümer',
            'KIND' => 'individual',
            'BDAY' => '1980-05-17',
        ]);

        $this->assertSame('uid-123', $local['vcard_uid']);
        $this->assertSame('Muster', $local['last_name']);
        $this->assertSame('Max', $local['first_name']);
        $this->assertSame('+490211123456', $local['phones'][0]['value']);
        $this->assertSame(['Mieter', 'Eigentümer'], $local['extra_properties']['categories']);
        $this->assertSame('person', $local['kind']);
        $this->assertSame('1980-05-17', $local['birth_date']);
        $this->assertSame('uid-123', $mapper->externalId(['UID' => 'uid-123']));
        $this->assertSame('/adr/x.vcf', $mapper->externalId(['href' => '/adr/x.vcf']));
        $this->assertSame(['UID' => 'uid-123'], $mapper->toExternal(['vcard_uid' => 'uid-123']));
    }

    public function test_ical_external_id_includes_recurrence_id(): void
    {
        $service = $this->app->make(FieldMappingService::class);
        $service->seedDefaults();

        $this->assertSame('ev-1|20260912T100000Z', $service->mapper('calendar_event', 'ical')->externalId(['UID' => 'ev-1', 'RECURRENCE-ID' => '20260912T100000Z']));
    }
}
