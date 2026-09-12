<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Models\Task;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class InboxBulkTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_bulk_assign_and_category_change_visible_cases_only(): void
    {
        $lead = $this->actingAsMailRole('lead');
        $agent = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($agent, $this->mailbox, 'agent');
        $a = $this->createCase($this->mailbox, ['status_processing' => 'new', 'assignee_user_id' => null]);
        $b = $this->createCase($this->mailbox);
        $hidden = $this->createCase($this->createMailbox(null, null, null, ['organization_id' => $this->mailbox->organization_id, 'label' => 'Fremd']));

        $this->post(self::BASE.'/inbox/bulk', [
            'action' => 'assign',
            'case_ids' => [$a->getKey(), $b->getKey(), $hidden->getKey()],
            'assignee_user_id' => $agent->getKey(),
            'next_step' => 'Rückruf',
            'due_at' => '30.09.2026 10:00',
        ])->assertRedirect(self::BASE.'/inbox');

        $this->assertSame($agent->getKey(), (int) $a->fresh()->assignee_user_id);
        $this->assertSame('open', $a->fresh()->status_processing->value);
        $this->assertSame('2026-09-30 08:00:00', $a->fresh()->due_at->format('Y-m-d H:i:s'));
        $this->assertNull($hidden->fresh()->assignee_user_id);
        $this->assertTrue(CaseStatusLog::query()->where('case_id', $a->getKey())->where('dimension', 'assignment')->exists());

        $this->post(self::BASE.'/inbox/bulk', ['action' => 'category', 'case_ids' => [$b->getKey()], 'case_type' => 'schaden'])->assertRedirect();
        $this->assertSame('schaden', $b->fresh()->case_type);

        $this->post(self::BASE.'/inbox/bulk', ['action' => 'task', 'case_ids' => [$b->getKey()], 'task_title' => 'Handwerker anrufen'])->assertRedirect();
        $this->assertTrue(Task::query()->where('case_id', $b->getKey())->where('title', 'Handwerker anrufen')->exists());
    }

    public function test_bulk_approve_or_send_is_rejected_and_no_bulk_approval_route_exists(): void
    {
        $this->actingAsMailRole('lead');
        $case = $this->createCase($this->mailbox);

        $this->post(self::BASE.'/inbox/bulk', ['action' => 'approve', 'case_ids' => [$case->getKey()]])->assertSessionHasErrors('action');
        $this->post(self::BASE.'/inbox/bulk', ['action' => 'send', 'case_ids' => [$case->getKey()]])->assertSessionHasErrors('action');

        $names = array_filter(array_keys(Route::getRoutes()->getRoutesByName()), static fn (string $name): bool => str_starts_with($name, 'mail.'));

        foreach ($names as $name) {
            $this->assertStringNotContainsString('bulk_approve', $name);
            $this->assertStringNotContainsString('approvals.bulk', $name);
            $this->assertStringNotContainsString('approve_all', $name);
        }

        $this->assertNull(Route::getRoutes()->getByName('mail.approvals.bulk'));
        $this->post(self::BASE.'/approvals/bulk')->assertNotFound();
    }

    public function test_agent_without_assign_permission_is_skipped_in_bulk(): void
    {
        $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => null]);

        $response = $this->post(self::BASE.'/inbox/bulk', ['action' => 'category', 'case_ids' => [$case->getKey()], 'case_type' => 'schaden']);

        $response->assertRedirect();
        $this->assertSame('bankdaten', $case->fresh()->case_type);
    }
}
