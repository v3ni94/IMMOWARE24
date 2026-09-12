<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Models\Team;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\MailUi\Services\OrgSettings;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_wizard_saves_configuration_step_by_step(): void
    {
        $admin = $this->actingAsMailRole('admin');
        $organizationId = (int) $this->mailbox->organization_id;
        $lead = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $agent = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $settings = app(OrgSettings::class);

        $this->post(self::BASE.'/admin/setup/organization', ['display_name' => 'Hausverwaltung Müller GmbH', 'default_legal_entity_code' => 'HVM'])
            ->assertRedirect(self::BASE.'/admin/setup/team');
        $this->assertSame('HVM', $settings->get($organizationId, 'organization')['default_legal_entity_code']);

        $this->post(self::BASE.'/admin/setup/team', ['name' => 'Verwaltung', 'lead_user_id' => $lead->getKey(), 'members' => [$agent->getKey()]])->assertRedirect(self::BASE.'/admin/setup/hours');
        $team = Team::query()->where('slug', 'verwaltung')->first();
        $this->assertNotNull($team);
        $this->assertSame('lead', TeamMember::query()->where('team_id', $team->getKey())->where('user_id', $lead->getKey())->value('team_role'));
        $this->assertSame('agent', TeamMember::query()->where('team_id', $team->getKey())->where('user_id', $agent->getKey())->value('team_role'));

        $this->post(self::BASE.'/admin/setup/hours', ['name' => 'Standard', 'hours' => ['mon' => ['start' => '08:00', 'end' => '16:30'], 'fri' => ['start' => '08:00', 'end' => '14:00']]])->assertRedirect(self::BASE.'/admin/setup/holidays');
        $calendar = WorkCalendar::query()->where('is_default', true)->first();
        $this->assertSame('14:00', $calendar->weekly_hours_json['fri']['end']);

        $this->post(self::BASE.'/admin/setup/holidays', ['holidays' => "03.10.2026; Tag der Deutschen Einheit\n01.11.2026; Allerheiligen\nkaputt", 'region' => 'NW'])->assertRedirect(self::BASE.'/admin/setup/mailboxes');
        $this->assertSame(2, Holiday::query()->where('work_calendar_id', $calendar->getKey())->count());

        $this->post(self::BASE.'/admin/setup/mailboxes', ['label' => 'Zentrale', 'email_address' => 'zentrale@muellerhv.de', 'team_id' => $team->getKey(), 'legal_entity_code' => 'HVM', 'aliases' => 'info@muellerhv.de, zentrale@muellerhv.de'])
            ->assertRedirect(self::BASE.'/admin/setup/responsibilities');
        $mailbox = Mailbox::query()->where('email_address', 'zentrale@muellerhv.de')->first();
        $this->assertSame('not_configured', $mailbox->status);
        $this->assertSame(['info@muellerhv.de'], MailboxAlias::query()->where('mailbox_id', $mailbox->getKey())->pluck('send_as_email')->all());

        // Alias darf im Assistenten nicht als Postfach angelegt werden.
        $this->post(self::BASE.'/admin/setup/mailboxes', ['label' => 'Info', 'email_address' => 'info@muellerhv.de', 'legal_entity_code' => 'HVM'])->assertSessionHasErrors('email_address');

        $this->post(self::BASE.'/admin/setup/responsibilities', [])->assertRedirect(self::BASE.'/admin/setup/alerts');
        $this->post(self::BASE.'/admin/setup/alerts', ['user_ids' => [$lead->getKey()], 'emails' => 'notfall@muellerhv.de; ungueltig'])->assertRedirect(self::BASE.'/admin/setup/approvers');
        $this->assertSame(['notfall@muellerhv.de'], $settings->get($organizationId, OrgSettings::ESCALATION_RECIPIENTS)['emails']);
        $this->post(self::BASE.'/admin/setup/approvers', ['standard_user_ids' => [$lead->getKey()], 'bank_user_ids' => [$admin->getKey()]])->assertRedirect(self::BASE.'/admin/setup/flags');
        $this->assertSame([$admin->getKey()], $settings->get($organizationId, OrgSettings::APPROVERS)['bank_user_ids']);

        $progress = $settings->get($organizationId, OrgSettings::SETUP);
        $this->assertEqualsCanonicalizing(['organization', 'team', 'hours', 'holidays', 'mailboxes', 'responsibilities', 'alerts', 'approvers'], $progress['done']);

        $this->get(self::BASE.'/admin/setup/flags')->assertOk()->assertSee('gmail_send')->assertSee('nur Anzeige');
        $this->get(self::BASE.'/admin/setup/unbekannt')->assertNotFound();
    }
}
