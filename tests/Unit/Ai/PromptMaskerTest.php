<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\Services\PromptMasker;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class PromptMaskerTest extends TestCase
{
    private function masker(bool $phone = true, bool $email = true): PromptMasker
    {
        return new PromptMasker(new Repository(['hub' => ['ai' => ['masking' => [
            'iban' => true, 'phone' => $phone, 'email' => $email, 'own_domains' => ['muellerhv.de'],
        ]]]]));
    }

    public function test_iban_is_always_masked_and_restored_server_side(): void
    {
        $masker = $this->masker(false, false);
        $text = 'Bitte auf DE89 3704 0044 0532 0130 00 überweisen, alternativ DE89370400440532013000.';

        $masked = $masker->mask($text);

        $this->assertStringNotContainsString('DE89', $masked);
        $this->assertStringContainsString('[IBAN_1]', $masked);
        $this->assertSame(1, $masker->placeholderCount(), 'Gleiche IBAN mit und ohne Leerzeichen erhält denselben Platzhalter.');
        $this->assertSame('Konto [IBAN_1] bestätigt.', $masker->mask('Konto DE89370400440532013000 bestätigt.'));
        $this->assertSame('Konto DE89370400440532013000 bestätigt.', $masker->unmask('Konto [IBAN_1] bestätigt.'));
    }

    public function test_invalid_iban_checksum_is_not_masked(): void
    {
        $masker = $this->masker(false, false);

        $this->assertSame('Referenz DE00370400440532013000 ohne Prüfziffer.', $masker->mask('Referenz DE00370400440532013000 ohne Prüfziffer.'));
    }

    public function test_phone_and_third_party_email_are_optional_and_own_domain_stays(): void
    {
        $masker = $this->masker();
        $masked = $masker->mask('Rückruf unter 0211 123456-78 oder mieter@example.com, Kopie an info@muellerhv.de.');

        $this->assertStringContainsString('[TEL_1]', $masked);
        $this->assertStringContainsString('[EMAIL_1]', $masked);
        $this->assertStringContainsString('info@muellerhv.de', $masked);
        $this->assertStringNotContainsString('mieter@example.com', $masked);

        $off = $this->masker(false, false)->mask('Rückruf unter 0211 123456-78 oder mieter@example.com.');
        $this->assertStringContainsString('0211 123456-78', $off);
        $this->assertStringContainsString('mieter@example.com', $off);
    }

    public function test_mask_array_and_unmask_array_are_recursive_and_unknown_placeholders_stay(): void
    {
        $masker = $this->masker();
        $masked = $masker->maskArray(['untrusted' => [['content' => 'IBAN DE89370400440532013000']], 'trusted' => ['note' => 'ok']]);

        $this->assertSame('IBAN [IBAN_1]', $masked['untrusted'][0]['content']);
        $this->assertSame(['body' => 'Zahlung an DE89370400440532013000 und [IBAN_9]'], $masker->unmaskArray(['body' => 'Zahlung an [IBAN_1] und [IBAN_9]']));
        $this->assertSame(['[IBAN_1]' => 'DE89370400440532013000'], $masker->mapping());
    }
}
