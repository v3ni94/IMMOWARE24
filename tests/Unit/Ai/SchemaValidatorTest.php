<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\Services\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SchemaValidator;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['priority', 'quotes', 'confidence'],
            'additionalProperties' => false,
            'properties' => [
                'priority' => ['type' => 'string', 'enum' => ['p0', 'p1']],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'date' => ['type' => ['string', 'null'], 'format' => 'date'],
                'quotes' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'object', 'required' => ['quote'], 'properties' => ['quote' => ['type' => 'string', 'maxLength' => 5]], 'additionalProperties' => false]],
            ],
        ];
    }

    public function test_valid_document_passes(): void
    {
        $errors = $this->validator->validate(['priority' => 'p0', 'confidence' => 80, 'date' => '2026-09-12', 'quotes' => [['quote' => 'abc']]], $this->schema());

        $this->assertSame([], $errors);
    }

    public function test_type_enum_required_and_additional_properties_are_reported(): void
    {
        $errors = $this->validator->validate(['priority' => 'p9', 'confidence' => '80', 'extra' => 1, 'quotes' => [['quote' => 'toolong', 'x' => 1]]], $this->schema());

        $this->assertContains('$.priority: Wert nicht in Aufzählung', $errors);
        $this->assertContains('$.confidence: erwartet Typ integer, erhalten string', $errors);
        $this->assertContains('$: unbekanntes Feld extra', $errors);
        $this->assertContains('$.quotes[0].quote: länger als 5 Zeichen', $errors);
        $this->assertContains('$.quotes[0]: unbekanntes Feld x', $errors);
    }

    public function test_missing_required_format_and_bounds(): void
    {
        $errors = $this->validator->validate(['priority' => 'p0', 'confidence' => 101, 'date' => '2026-13-40', 'quotes' => [[], [], []]], $this->schema());

        $this->assertContains('$.confidence: größer als Maximum 100', $errors);
        $this->assertContains('$.date: entspricht nicht Format date', $errors);
        $this->assertContains('$.quotes: mehr als 2 Einträge', $errors);
        $this->assertContains('$.quotes[0]: Pflichtfeld quote fehlt', $errors);
    }

    public function test_nullable_union_type_and_masked_iban_format(): void
    {
        $this->assertSame([], $this->validator->validate(null, ['type' => ['string', 'null']]));
        $this->assertSame([], $this->validator->validate('[IBAN_3]', ['type' => 'string', 'format' => 'iban_masked']));
        $this->assertNotSame([], $this->validator->validate('DE89370400440532013000', ['type' => 'string', 'format' => 'iban_masked']), 'Klartext-IBAN darf das Format nicht erfüllen.');
        $this->assertNotSame([], $this->validator->validate('nicht-objekt', $this->schema()));
    }
}
