<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Modules\Cases\Services\CaseService;
use App\Modules\Estate\Models\Property;
use App\Modules\Paperless\PaperlessServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Cases\CasesTestCase;

final class CasePaperlessDocumentsTest extends CasesTestCase
{
    private const string BASE = 'https://mail.muellerhv.de';

    private function caseWithProperty(bool $withProperty = true): int
    {
        Queue::fake();
        $agent = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Heizung defekt']);
        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'schaden', 'title' => 'Heizung', 'assignee_user_id' => $agent->getKey()]], $agent);

        if ($withProperty) {
            $property = Property::factory()->create(['organization_id' => $this->mailbox->organization_id, 'immoware_object_number' => 'OBJ-0042']);
            $case->forceFill(['property_id' => $property->getKey()])->save();
        }

        return (int) $case->getKey();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.paperless.base_url', 'https://dms.muellerhv.de');
        config()->set('hub.paperless.api_token', 'token');
        config()->set('hub.paperless.retry.times', 1);
        config()->set('hub.paperless.object_number_field_id', 7);
        config()->set('hub.mail.providers.paperless', 'live');
        $this->refreshApplicationBindings();
    }

    private function refreshApplicationBindings(): void
    {
        (new PaperlessServiceProvider($this->app))->register();
    }

    public function test_case_view_lists_paperless_documents_of_property(): void
    {
        Http::fake(['https://dms.muellerhv.de/api/documents/*' => Http::response(['count' => 12, 'next' => 'x', 'results' => [
            ['id' => 5, 'title' => 'Wartungsvertrag Heizung', 'created' => '2026-03-01', 'custom_fields' => [['field' => 7, 'value' => 'OBJ-0042']]],
        ]])]);

        $id = $this->caseWithProperty();

        $this->get(self::BASE.'/cases/'.$id)->assertOk()
            ->assertSee('Paperless zum Objekt')
            ->assertSee('Wartungsvertrag Heizung')
            ->assertSee('https://dms.muellerhv.de/documents/5/details', false)
            ->assertSee('1 von 12 angezeigt.');
    }

    public function test_case_view_survives_paperless_outage(): void
    {
        Http::fake(['https://dms.muellerhv.de/api/documents/*' => Http::response('kaputt', 500)]);

        $this->get(self::BASE.'/cases/'.$this->caseWithProperty())->assertOk()->assertSee('Paperless derzeit nicht erreichbar.');
    }

    public function test_case_without_property_does_not_query_paperless(): void
    {
        Http::fake();

        $this->get(self::BASE.'/cases/'.$this->caseWithProperty(false))->assertOk()->assertSee('Kein Objekt zugeordnet');
        Http::assertNothingSent();
    }
}
