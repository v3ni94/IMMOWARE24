<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Models\MailboxPermission;
use App\Modules\Mail\Models\Team;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\MailUi\Services\OrgSettings;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\SlaRule;
use App\Modules\Sla\Models\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminMailboxesTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_alias_cannot_be_created_as_mailbox_and_mailbox_not_as_alias(): void
    {
        $this->actingAsMailRole('admin');
        MailboxAlias::query()->create(['mailbox_id' => $this->mailbox->getKey(), 'send_as_email' => 'info@muellerhv.de', 'legal_entity_code' => 'HVM']);

        $this->post(self::BASE.'/admin/mailboxes', [
            'label' => 'Info',
            'email_address' => 'info@muellerhv.de',
            'legal_entity_code' => 'HVM',
        ])->assertRedirect(self::BASE.'/admin/mailboxes')->assertSessionHasErrors('email_address');

        $this->assertSame(1, Mailbox::query()->count());

        $this->post(self::BASE.'/admin/mailboxes/'.$this->mailbox->getKey().'/aliases', [
            'send_as_email' => $this->mailbox->email_address,
            'legal_entity_code' => 'HVM',
        ])->assertSessionHasErrors('send_as_email');

        $this->assertSame(1, MailboxAlias::query()->count());
    }

    public function test_mailbox_creation_starts_not_configured_and_is_audited(): void
    {
        $this->actingAsMailRole('admin');
        $team = Team::query()->first();

        $this->post(self::BASE.'/admin/mailboxes', [
            'label' => 'Verwaltung',
            'email_address' => 'verwaltung@muellerhv.de',
            'legal_entity_code' => 'HVM',
            'team_id' => $team->getKey(),
        ])->assertRedirect(self::BASE.'/admin/mailboxes')->assertSessionHasNoErrors();

        $mailbox = Mailbox::query()->where('email_address', 'verwaltung@muellerhv.de')->first();
        $this->assertNotNull($mailbox);
        $this->assertSame('not_configured', $mailbox->status);
        $this->assertTrue(AuditLog::query()->where('action', 'mail.admin.mailbox_created')->exists());

        $this->post(self::BASE.'/admin/mailboxes', ['label' => 'Falsch', 'email_address' => 'x@muellerhv.de', 'legal_entity_code' => 'UNBEKANNT'])->assertSessionHasErrors('legal_entity_code');
    }

    public function test_permissions_and_team_members_can_be_managed(): void
    {
        $admin = $this->actingAsMailRole('admin');
        $other = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $team = Team::query()->first();

        $this->post(self::BASE.'/admin/teams/'.$team->getKey().'/members', ['user_id' => $other->getKey(), 'team_role' => 'approver'])->assertRedirect();
        $this->assertSame('approver', TeamMember::query()->where('user_id', $other->getKey())->value('team_role'));
        $this->post(self::BASE.'/admin/teams/'.$team->getKey().'/members', ['user_id' => $other->getKey(), 'team_role' => 'chef'])->assertSessionHasErrors('team_role');

        $this->post(self::BASE.'/admin/mailboxes/'.$this->mailbox->getKey().'/permissions', ['user_id' => $other->getKey(), 'can_read' => 1, 'can_view_bank_data' => 1])->assertRedirect();
        $permission = MailboxPermission::query()->where('mailbox_id', $this->mailbox->getKey())->where('user_id', $other->getKey())->first();
        $this->assertTrue((bool) $permission->can_read);
        $this->assertTrue((bool) $permission->can_view_bank_data);
        $this->assertFalse((bool) $permission->can_send);

        $this->post(self::BASE.'/admin/mailboxes/'.$this->mailbox->getKey().'/permissions', ['user_id' => $other->getKey()])->assertRedirect();
        $this->assertNull(MailboxPermission::query()->where('mailbox_id', $this->mailbox->getKey())->where('user_id', $other->getKey())->first());

        $this->post(self::BASE.'/admin/teams', ['name' => 'Buchhaltung'])->assertRedirect();
        $this->assertTrue(Team::query()->where('slug', 'buchhaltung')->exists());
    }

    public function test_sla_rules_calendars_and_settings_are_saved(): void
    {
        $this->actingAsMailRole('admin');

        $this->post(self::BASE.'/admin/sla', ['priority' => 'p0', 'clock_type' => 'acknowledge', 'target_minutes' => 10, 'escalate_after_minutes' => 5, 'escalate_to_role' => 'lead'])->assertRedirect();
        $rule = SlaRule::query()->first();
        $this->assertNotNull($rule);
        $this->assertFalse((bool) $rule->uses_calendar);

        $this->post(self::BASE.'/admin/calendars', ['name' => 'Standard', 'is_default' => 1, 'hours' => ['mon' => ['start' => '08:00', 'end' => '16:30'], 'sat' => ['start' => '', 'end' => '']]])->assertRedirect();
        $calendar = WorkCalendar::query()->first();
        $this->assertSame(['mon' => ['start' => '08:00', 'end' => '16:30']], $calendar->weekly_hours_json);

        $this->post(self::BASE.'/admin/calendars/'.$calendar->getKey().'/holidays', ['holiday_date' => '03.10.2026', 'label' => 'Tag der Deutschen Einheit'])->assertRedirect();
        $this->assertStringStartsWith('2026-10-03', (string) Holiday::query()->first()->getRawOriginal('holiday_date'));

        $this->put(self::BASE.'/admin/settings/ai_budget', ['monthly_limit_cents' => 5000, 'warn_percent' => 80])->assertRedirect();
        $this->assertSame(5000, app(OrgSettings::class)->get((int) $this->mailbox->organization_id, 'ai_budget')['monthly_limit_cents']);
        $this->put(self::BASE.'/admin/settings/setup', [])->assertNotFound();
    }
}
