<?php

declare(strict_types=1);

namespace App\Modules\Imports\DTO;

use Carbon\CarbonImmutable;

/**
 * Eine Buchung (Ntry) aus einem camt.053-Kontoauszug. IBAN liegt nur maskiert vor.
 */
final readonly class CamtEntry
{
    public function __construct(
        public string $accountIbanMasked,
        public int $amountCents,
        public string $currency,
        public ?CarbonImmutable $bookingDate,
        public ?CarbonImmutable $valueDate,
        public ?string $remittanceInfo,
        public ?string $counterpartyName,
        public ?string $counterpartyIbanMasked,
        public ?string $endToEndId,
        public ?string $acctSvcrRef,
        public ?string $status,
    ) {}
}
