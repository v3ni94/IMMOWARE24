<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Core\Contracts\Mail\DocumentSourceInterface;
use App\Core\Contracts\Mail\MailboxProviderInterface;
use App\Modules\Ai\Testing\FakeAiProvider;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Lexware\Testing\FakeLexwareApi;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Services\IntegrationStatusService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FakeProviderBindingTest extends TestCase
{
    public function test_testing_environment_binds_fakes_for_gmail_and_ai(): void
    {
        $this->assertInstanceOf(FakeGmailProvider::class, $this->app->make(MailboxProviderInterface::class));
        $this->assertInstanceOf(FakeAiProvider::class, $this->app->make(AiProviderInterface::class));
        $this->assertSame($this->app->make(MailboxProviderInterface::class), $this->app->make(MailboxProviderInterface::class), 'Fake ist Singleton, damit Tests seeden können.');
    }

    public function test_drive_without_configuration_is_visibly_not_configured(): void
    {
        $status = $this->app->make(IntegrationStatusService::class);

        $this->assertSame('Nicht eingerichtet', $status->label('drive'));
        $this->assertSame('Nicht eingerichtet', $status->overview()['gmail']['label']);

        $this->expectException(MailIntegrationNotConfiguredException::class);
        $this->app->make(DocumentSourceInterface::class)->search('Protokoll');
    }

    public function test_fake_gmail_provider_tracks_history_drafts_and_sent(): void
    {
        $gmail = new FakeGmailProvider;
        $id = $gmail->seedMessage(7, ['from' => 'mieter@example.com', 'subject' => 'Wasserschaden', 'text' => 'Es tropft.']);
        $start = $gmail->currentHistoryId(7);

        $this->assertSame([['id' => $id, 'thread_id' => 'fake-thread-'.$id]], $gmail->listMessages(7, ['label_ids' => ['INBOX']])['messages']);
        $this->assertArrayNotHasKey('text', $gmail->getMessage(7, $id));
        $this->assertSame('Es tropft.', $gmail->getMessage(7, $id, 'full')['text']);
        $this->assertSame([], $gmail->listHistory(7, $start)['history']);

        $watch = $gmail->watch(7, 'projects/x/topics/y');
        $this->assertSame($start, $watch['history_id']);

        $draft = $gmail->createDraft(7, ['from' => 'hv@example.com', 'to' => ['mieter@example.com'], 'subject' => 'Re: Wasserschaden', 'text' => 'Wir kümmern uns.', 'message_id' => '<r1@hub>']);
        $this->assertCount(1, $gmail->drafts(7));

        $sent = $gmail->sendDraft(7, $draft['draft_id']);
        $this->assertSame([], $gmail->drafts(7));
        $this->assertSame([$sent['message_id']], $gmail->sentIds(7));
        $this->assertCount(1, $gmail->listSent(7, '<r1@hub>')['messages']);
        $this->assertCount(0, $gmail->listSent(7, '<anders@hub>')['messages']);
        $this->assertCount(1, $gmail->listHistory(7, $start)['history']);
        $this->assertNotEmpty($gmail->calls('sendDraft'));
    }

    public function test_fake_ai_provider_returns_queued_structured_answers(): void
    {
        $ai = new FakeAiProvider;
        $ai->queue('classify_case', ['case_type' => 'schaden_notfall', 'priority' => 'p0']);
        $ai->setDefault('classify_case', ['case_type' => 'sonstiges', 'priority' => 'p3']);

        $first = $ai->structured('classify_case', ['subject' => '[MASKIERT]'], ['type' => 'object']);
        $second = $ai->structured('classify_case', [], ['type' => 'object']);

        $this->assertSame('p0', $first['priority']);
        $this->assertSame('fake', $first['meta']['provider']);
        $this->assertSame('sonstiges', $second['case_type']);
        $this->assertCount(2, $ai->calls('classify_case'));
    }

    public function test_fake_lexware_api_answers_get_put_with_version_check(): void
    {
        $fake = (new FakeLexwareApi)->seedContact('c-1', ['person' => ['lastName' => 'Muster']], 3);
        $fake->install();

        $get = Http::withToken('key')->get('https://api.lexoffice.io/v1/contacts/c-1');
        $this->assertSame(3, $get->json('version'));

        $conflict = Http::withToken('key')->put('https://api.lexoffice.io/v1/contacts/c-1', ['version' => 2, 'person' => ['lastName' => 'Neu']]);
        $this->assertSame(409, $conflict->status());

        $ok = Http::withToken('key')->put('https://api.lexoffice.io/v1/contacts/c-1', ['version' => 3, 'person' => ['lastName' => 'Neu']]);
        $this->assertSame(4, $ok->json('version'));
        $this->assertSame('Neu', $fake->contacts()['c-1']['person']['lastName']);

        $this->assertSame(401, Http::get('https://api.lexoffice.io/v1/contacts/c-1')->status());
        $fake->failNext('contacts/c-1', 503);
        $this->assertSame(503, Http::withToken('key')->get('https://api.lexoffice.io/v1/contacts/c-1')->status());
    }
}
