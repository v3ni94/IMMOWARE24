<?php

declare(strict_types=1);

namespace Tests\Unit\MailUi;

use App\Modules\MailUi\Services\NullCaseCommand;
use App\Modules\MailUi\Support\BankDataMasker;
use PHPUnit\Framework\TestCase;

final class BankDataMaskerTest extends TestCase
{
    public function test_iban_is_masked_keeping_country_and_check_digits(): void
    {
        $this->assertSame('DE02 **** **** **** **** 51', BankDataMasker::maskIban('DE02 1203 0000 0000 2020 51'));
        $this->assertSame('******', BankDataMasker::maskIban('ABC123'));
    }

    public function test_mask_array_marks_sensitive_keys_and_reveal_returns_plain_values(): void
    {
        $values = ['IBAN' => 'DE02120300000000202051', 'BIC' => 'BYLADEM1001', 'name' => 'Max Beispiel'];

        $masked = BankDataMasker::maskArray($values);
        $this->assertSame('DE02 **** **** **** **** 51 (maskiert)', $masked['IBAN']);
        $this->assertSame('BY********* (maskiert)', $masked['BIC']);
        $this->assertSame('Max Beispiel', $masked['name']);
        $this->assertTrue(BankDataMasker::containsSensitive($values));
        $this->assertFalse(BankDataMasker::containsSensitive(['name' => 'x']));

        $this->assertSame('DE02120300000000202051', BankDataMasker::maskArray($values, true)['IBAN']);
    }

    public function test_berlin_input_is_stored_as_utc(): void
    {
        $summer = NullCaseCommand::parseBerlin('15.07.2026 10:00');
        $winter = NullCaseCommand::parseBerlin('15.01.2026 10:00');

        $this->assertSame('2026-07-15 08:00', $summer?->format('Y-m-d H:i'));
        $this->assertSame('2026-01-15 09:00', $winter?->format('Y-m-d H:i'));
        $this->assertSame('2026-01-14 23:00', NullCaseCommand::parseBerlin('15.01.2026')?->format('Y-m-d H:i'));
        $this->assertNull(NullCaseCommand::parseBerlin('morgen'));
    }
}
