<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * KI-Vorschläge liegen verschlüsselt in mail_ai_suggestions.payload_json (Cast encrypted:array, Spalte longText).
 */
final class AiSuggestionEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_is_stored_encrypted_and_read_back_as_array(): void
    {
        $run = AiRun::query()->create(['organization_id' => $this->createOrganization()->getKey(), 'task' => 'classify_case', 'provider' => 'fake', 'model' => 'fake-1', 'input_hash' => str_repeat('a', 64), 'schema_hash' => str_repeat('b', 64), 'status' => 'succeeded', 'started_at' => now()]);
        $payload = ['category' => 'Wasserschaden', 'iban_hint' => 'DE00 **** 1234', 'confidence_percent' => 91];

        $suggestion = AiSuggestion::query()->create(['ai_run_id' => $run->getKey(), 'suggestion_type' => 'classification', 'payload_json' => $payload, 'status' => 'proposed']);

        $raw = DB::table('mail_ai_suggestions')->where('id', $suggestion->getKey())->value('payload_json');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('Wasserschaden', $raw, 'Klartext darf nicht in der Datenbank stehen.');
        $this->assertStringNotContainsString('1234', $raw);
        $this->assertSame($payload, AiSuggestion::query()->findOrFail($suggestion->getKey())->getAttribute('payload_json'));
        $this->assertNull(AiSuggestion::query()->create(['ai_run_id' => $run->getKey(), 'suggestion_type' => 'summary', 'payload_json' => null, 'status' => 'superseded'])->refresh()->getAttribute('payload_json'), 'Geleerte Altzeilen sind lesbar (NULL).');
    }

    public function test_column_is_text_and_nullable_after_migration(): void
    {
        $column = collect(Schema::getColumns('mail_ai_suggestions'))->firstWhere('name', 'payload_json');

        $this->assertNotNull($column);
        $this->assertTrue((bool) $column['nullable']);
        $this->assertStringContainsString('text', strtolower((string) $column['type']));
    }
}
