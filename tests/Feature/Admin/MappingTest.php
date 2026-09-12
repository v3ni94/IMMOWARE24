<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Admin\Http\Requests\MappingVersionRequest;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Models\FieldMapping;
use App\Modules\Sync\Services\FieldMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MappingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->app->make(FieldMappingService::class)->seedDefaults();
    }

    private function loginAs(Role $role): User
    {
        $user = User::factory()->role($role)->for($this->organization)->create();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        return $user;
    }

    public function test_index_and_show_render_for_read_only(): void
    {
        $this->loginAs(Role::ReadOnly);
        $active = FieldMapping::query()->where('entity_type', 'contact')->active()->firstOrFail();

        $this->get('/admin/mapping')->assertOk()->assertSee('Aktive Versionen')->assertSee('v1')->assertDontSee('Neue Version');
        $this->get('/admin/mapping/'.$active->getKey())->assertOk()->assertSee('N.family')->assertSee('last_name')->assertSee('Immoware-Feld');
    }

    public function test_read_only_cannot_create_or_activate(): void
    {
        $this->loginAs(Role::ReadOnly);

        $this->get('/admin/mapping/create?entity_type=contact')->assertForbidden();
        $this->post('/admin/mapping/review', ['entity_type' => 'contact', 'source_format' => 'vcard', 'rules_text' => 'UID => vcard_uid'])->assertForbidden();
        $this->post('/admin/mapping', ['entity_type' => 'contact', 'source_format' => 'vcard', 'rules_text' => 'UID => vcard_uid', 'confirmation' => 'BESTÄTIGEN'])->assertForbidden();
        $this->assertSame(1, FieldMapping::query()->where('entity_type', 'contact')->count());
    }

    public function test_review_shows_diff_and_rejects_unknown_transform(): void
    {
        $this->loginAs(Role::Administrator);

        $this->get('/admin/mapping/create?entity_type=contact&source_format=vcard')->assertOk()->assertSee('N.family =&gt; last_name | trim', false);

        $rules = "UID => vcard_uid | trim\nhref => vcard_href | trim\nN.family => last_name | upper\nX-NEU => extra_properties.neu";

        $this->post('/admin/mapping/review', ['entity_type' => 'contact', 'source_format' => 'vcard', 'rules_text' => $rules, 'notes' => 'Test'])
            ->assertOk()
            ->assertSee('data-diff-state="geändert"', false)
            ->assertSee('data-diff-state="neu"', false)
            ->assertSee('data-diff-state="entfernt"', false)
            ->assertSee('Version aktivieren');

        $this->from('/admin/mapping/create')->post('/admin/mapping/review', ['entity_type' => 'contact', 'source_format' => 'vcard', 'rules_text' => 'UID => vcard_uid | zaubern'])
            ->assertRedirect('/admin/mapping/create')
            ->assertSessionHasErrors('rules_text');
    }

    public function test_store_publishes_new_version_with_confirmation_and_audit(): void
    {
        $user = $this->loginAs(Role::Administrator);
        $v1 = FieldMapping::query()->where('entity_type', 'contact')->active()->firstOrFail();
        $rules = "UID => vcard_uid | trim\nhref => vcard_href | trim\nN.family => last_name | upper";

        $this->post('/admin/mapping', ['entity_type' => 'contact', 'source_format' => 'vcard', 'rules_text' => $rules, 'notes' => 'Nachname groß'])->assertStatus(422);
        $this->assertSame(1, FieldMapping::query()->where('entity_type', 'contact')->count());

        $this->post('/admin/mapping', ['entity_type' => 'contact', 'source_format' => 'vcard', 'rules_text' => $rules, 'notes' => 'Nachname groß', 'confirmation' => 'BESTÄTIGEN'])->assertRedirect();

        $v2 = FieldMapping::query()->where('entity_type', 'contact')->active()->firstOrFail();
        $this->assertSame(2, (int) $v2->getAttribute('version'));
        $this->assertSame((int) $user->getKey(), (int) $v2->getAttribute('activated_by'));
        $this->assertSame('retired', $v1->refresh()->getAttribute('status'));
        $this->assertSame((int) $v1->getKey(), (int) $v2->getAttribute('previous_version_id'));

        $audit = AuditLog::query()->where('action', 'admin.mapping.activated')->firstOrFail();
        $this->assertSame(2, (int) $audit->getAttribute('after_json')['version']);
        $this->assertSame(1, (int) $audit->getAttribute('before_json')['version']);

        $this->get('/admin/mapping/compare?a='.$v1->getKey().'&b='.$v2->getKey())->assertOk()->assertSee('data-diff-state="entfernt"', false);
    }

    public function test_store_with_unchanged_rules_creates_no_version(): void
    {
        $this->loginAs(Role::Owner);
        $v1 = FieldMapping::query()->where('entity_type', 'document')->active()->firstOrFail();
        $text = MappingVersionRequest::rulesToText((array) $v1->getAttribute('mapping'));

        $this->post('/admin/mapping', ['entity_type' => 'document', 'source_format' => 'webdav', 'rules_text' => $text, 'confirmation' => 'BESTÄTIGEN'])
            ->assertRedirect('/admin/mapping/'.$v1->getKey())
            ->assertSessionHas('warning');

        $this->assertSame(1, FieldMapping::query()->where('entity_type', 'document')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'admin.mapping.activated')->count());
    }
}
