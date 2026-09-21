<?php

declare(strict_types=1);

namespace Tests\Feature\Playbooks;

use App\Modules\Ai\Testing\FakeAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MailUi\CreatesMailCases;
use Tests\TestCase;

final class RelearnPlaybooksCommandTest extends TestCase
{
    use CreatesMailCases, RefreshDatabase;

    public function test_command_learns_from_closed_cases_up_to_the_limit(): void
    {
        config()->set('hub.playbooks.flags.ai', true);
        config()->set('hub.mail.flags.ai', true);
        config()->set('hub.mail.providers.ai', 'fake');

        $mailbox = $this->createMailbox();
        $this->createCase($mailbox, ['case_type' => 'schaden', 'closed_at' => now()]);
        $this->createCase($mailbox, ['case_type' => 'schaden', 'closed_at' => now()]);
        $this->createCase($mailbox, ['case_type' => 'schaden', 'closed_at' => null]);

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);
        $fake->setDefault('playbook_draft_steps', [
            'title' => 'Entwurf', 'notes' => [],
            'steps' => [['action_type' => 'internal_review', 'description' => 'prüfen', 'typical_offset_hours' => null, 'requires_approval' => false]],
        ]);

        $this->artisan('hub:playbooks:relearn', ['--organization' => $mailbox->getAttribute('organization_id')])
            ->assertSuccessful();

        // Nur der erste Entwurf wird angelegt, der zweite abgeschlossene Vorgang trifft auf den vorhandenen Entwurf (kein Duplikat).
        $this->assertDatabaseCount('mail_playbooks', 1);
    }
}
