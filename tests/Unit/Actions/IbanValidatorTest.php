<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Modules\Actions\Support\IbanValidator;
use PHPUnit\Framework\TestCase;

final class IbanValidatorTest extends TestCase
{
    private IbanValidator $iban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iban = new IbanValidator;
    }

    public function test_valid_ibans_pass_length_format_and_mod97(): void
    {
        $this->assertTrue($this->iban->isValid('DE89 3704 0044 0532 0130 00'));
        $this->assertTrue($this->iban->isValid('GB82WEST12345698765432'));
        $this->assertTrue($this->iban->isValid('AT611904300234573201'));
        $this->assertTrue($this->iban->isValid('CH9300762011623852957'));
    }

    public function test_invalid_ibans_are_rejected_with_reasons(): void
    {
        $this->assertContains('Prüfsumme (Mod 97) ist ungültig.', $this->iban->validate('DE88370400440532013000'));
        $this->assertSame(['IBAN für DE muss 22 Stellen haben, hat 21.', 'Prüfsumme (Mod 97) ist ungültig.'], $this->iban->validate('DE8937040044053201300'));
        $this->assertStringContainsString('nicht im IBAN-Register', $this->iban->validate('ZZ89370400440532013000')[0]);
        $this->assertNotSame([], $this->iban->validate('keine iban'));
    }

    public function test_masking_keeps_only_first_and_last_four_characters(): void
    {
        $this->assertSame('DE89 **** **** **** **30 00', $this->iban->mask('DE89 3704 0044 0532 0130 00'));
        $this->assertSame('GB82 **** **** **** **54 32', $this->iban->mask('GB82WEST12345698765432'));
        $this->assertSame('******', $this->iban->mask('DE8937'));
    }

    public function test_extracts_only_valid_ibans_from_text(): void
    {
        $text = 'Bitte künftig von DE89 3704 0044 0532 0130 00 abbuchen, nicht mehr von DE88370400440532013000. Tel 0211 123456.';

        $this->assertSame(['DE89370400440532013000'], $this->iban->extract($text));
        $this->assertSame([], $this->iban->extract('Kein Konto genannt.'));
    }
}
