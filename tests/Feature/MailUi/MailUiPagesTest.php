<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\CaseLock;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MailUiPagesTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    /**
     * @return array<string, array{string, string}>
     */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/', 'Übersicht'],
            'inbox' => ['/inbox', 'Posteingang (Team)'],
            'work' => ['/work', 'Meine Arbeit'],
            'approvals' => ['/approvals', 'Freigabecenter'],
            'integrations' => ['/integrations', 'Nicht eingerichtet'],
            'teams' => ['/admin/teams', 'Rechtematrix'],
            'mailboxes' => ['/admin/mailboxes', 'Alias ist kein Postfach'],
            'responsibilities' => ['/admin/responsibilities', 'Objektzuständigkeiten'],
            'calendars' => ['/admin/calendars', 'Arbeitszeiten und Feiertage'],
            'sla' => ['/admin/sla', 'SLA-Regeln'],
            'settings' => ['/admin/settings', 'Kostenlimit KI'],
            'setup' => ['/admin/setup', 'Einrichtungsassistent'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_every_page_returns_200_for_authorized_admin(string $path, string $expected): void
    {
        $this->actingAsMailRole('admin');

        $this->get(self::BASE.$path)->assertOk()->assertSee($expected);
    }

    public function test_case_detail_renders_three_panes_with_lock_and_mandatory_fields(): void
    {
        $user = $this->actingAsMailRole('lead');
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => null, 'next_step' => null, 'due_at' => null]);
        $other = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create(['name' => 'Kollegin Beispiel']);
        CaseLock::query()->create(['case_id' => $case->getKey(), 'user_id' => $other->getKey(), 'acquired_at' => now(), 'heartbeat_at' => now(), 'expires_at' => now()->addMinutes(5)]);

        $this->get(self::BASE.'/cases/'.$case->getKey())
            ->assertOk()
            ->assertSee('Originalthread')
            ->assertSee('Bearbeitung')
            ->assertSee('Kontext')
            ->assertSee('wird bearbeitet von Kollegin Beispiel')
            ->assertSee('Verantwortlicher, nächster Schritt, Fälligkeit fehlt')
            ->assertSee('Nächster Schritt: Zuweisen')
            ->assertSee('Interne Notizen (nie Teil einer Antwort)')
            ->assertSee('Versand gesperrt');
    }

    public function test_case_detail_is_forbidden_without_mailbox_permission(): void
    {
        $viewer = $this->actingAsMailRole('agent');
        $foreignMailbox = $this->createMailbox(null, null, null, ['organization_id' => $this->mailbox->organization_id, 'label' => 'Anderes Postfach']);
        $case = $this->createCase($foreignMailbox);

        $this->get(self::BASE.'/cases/'.$case->getKey())->assertForbidden();
        $this->get(self::BASE.'/inbox')->assertOk()->assertDontSee($case->case_number);
    }

    public function test_agent_has_no_access_to_admin_and_integrations_and_approvals(): void
    {
        $this->actingAsMailRole('agent');

        $this->get(self::BASE.'/admin/teams')->assertForbidden();
        $this->get(self::BASE.'/admin/setup')->assertForbidden();
        $this->get(self::BASE.'/integrations')->assertForbidden();
        $this->get(self::BASE.'/approvals')->assertForbidden();
    }

    public function test_navigation_lists_available_areas_and_marks_missing_as_in_construction(): void
    {
        $this->actingAsMailRole('admin');

        $this->get(self::BASE.'/')
            ->assertOk()
            ->assertSee('Meine Arbeit')
            ->assertSee('Einrichtungsassistent')
            ->assertSee('in Aufbau');
    }
}
