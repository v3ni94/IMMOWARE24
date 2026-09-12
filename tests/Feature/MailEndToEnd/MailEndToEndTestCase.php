<?php

declare(strict_types=1);

namespace Tests\Feature\MailEndToEnd;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Connector\Models\Organization;
use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Jobs\InitialImportJob;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Lexware\Models\LexwareConnection;
use App\Modules\Lexware\Testing\FakeLexwareApi;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Actions\Concerns\BuildsActionPlans;
use Tests\Support\Gmail\SignedIdToken;
use Tests\TestCase;

/**
 * Gemeinsame Fixtures der End-to-End-Tests: Postfach mit aktivem Watch, FakeGmailProvider, Pub/Sub-Push mit
 * signiertem ID-Token (eigenes RSA-Paar, kein Google-Zertifikat), Lexware-Fake, Rollen Sachbearbeitung und zwei
 * Freigebende. Alle Fremdsysteme sind Fakes; ein grüner Lauf ist kein Live-Nachweis gegen Gmail oder Lexware.
 */
abstract class MailEndToEndTestCase extends TestCase
{
    use BuildsActionPlans, RefreshDatabase;

    protected const string PUSH_URL = 'https://mail.muellerhv.de/mail/gmail/push';

    protected const string BASE = 'https://mail.muellerhv.de';

    protected const string MAILBOX_EMAIL = 'verwaltung@muellerhv.de';

    protected FakeGmailProvider $gmail;

    protected SignedIdToken $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = new SignedIdToken;
        config()->set('hub.gmail.push.topic', 'projects/hvm/topics/gmail');
        config()->set('hub.gmail.push.audience', self::PUSH_URL);
        config()->set('hub.gmail.push.service_account_email', 'pubsub-push@projekt.iam.gserviceaccount.com');
        config()->set('hub.mail.flags.import', true);
        config()->set('hub.mail.flags.lexware_write', true);
        config()->set('hub.mail.flags.immoware_write', false);
        config()->set('hub.lexware.rate_limit_rps', 1000);
        config()->set('hub.actions.jobs.verify_delay_seconds', 0);
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->tokens->jwks())]);

        $this->author = $this->actingAsMailRole('agent');
        $this->box = $this->createMailbox($this->author, 'agent', null, ['email_address' => self::MAILBOX_EMAIL, 'status' => 'active', 'import_enabled' => true, 'legal_entity_code' => 'HVM']);
        $organization = Organization::query()->findOrFail($this->box->organization_id);

        $this->approverOne = User::factory()->role(Role::Operator)->for($organization)->create(['name' => 'Freigabe Eins']);
        $this->approverTwo = User::factory()->role(Role::Operator)->for($organization)->create(['name' => 'Freigabe Zwei']);
        $this->attachMailRole($this->approverOne, $this->box, 'approver');
        $this->attachMailRole($this->approverTwo, $this->box, 'approver');

        $this->gmail = $this->app->make(GmailProviderInterface::class);
        // Erstimport auf leerem Postfach: setzt last_history_id, danach liefert jeder Push einen inkrementellen Abgleich.
        InitialImportJob::dispatch((int) $this->box->getKey());

        $this->lexware = new FakeLexwareApi;
        $this->lexware->install();
        LexwareConnection::query()->create([
            'organization_id' => $this->box->organization_id,
            'label' => 'HVM Lexware',
            'legal_entity_code' => 'HVM',
            'base_url' => 'https://api.lexoffice.io/v1',
            'api_key' => 'test-key-not-secret',
            'api_key_fingerprint' => 'abcd',
            'status' => 'configured',
            'write_enabled' => true,
        ]);
    }

    protected function push(string $historyId, string $pubsubMessageId): TestResponse
    {
        $payload = [
            'message' => [
                'data' => base64_encode(json_encode(['emailAddress' => self::MAILBOX_EMAIL, 'historyId' => $historyId], JSON_THROW_ON_ERROR)),
                'messageId' => $pubsubMessageId,
                'publishTime' => '2026-09-12T10:00:00.000Z',
            ],
            'subscription' => 'projects/hvm/subscriptions/gmail-push',
        ];

        return $this->postJson(self::PUSH_URL, $payload, ['Authorization' => 'Bearer '.$this->tokens->issue()]);
    }

    /**
     * Nachricht im Fake-Gmail ablegen und per Push importieren; liefert den importierten Spiegel-Datensatz.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function receive(array $attributes): MailMessage
    {
        $gmailId = $this->gmail->seedMessage((int) $this->box->getKey(), $attributes);
        $this->push($this->gmail->currentHistoryId((int) $this->box->getKey()), 'push-'.$gmailId)->assertNoContent();

        return MailMessage::query()->where('mailbox_id', $this->box->getKey())->where('gmail_message_id', $gmailId)->firstOrFail();
    }

    protected function caseOf(MailMessage $message): MailCase
    {
        $link = CaseMessage::query()->where('message_id', $message->getKey())->where('link_type', 'origin')->firstOrFail();

        return MailCase::query()->findOrFail($link->getAttribute('case_id'));
    }

    protected function mailboxModel(): Mailbox
    {
        return $this->box;
    }
}
