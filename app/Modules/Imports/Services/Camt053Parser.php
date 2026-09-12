<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Estate\Models\BankAccount;
use App\Modules\Imports\DTO\CamtEntry;
use App\Modules\Imports\Exceptions\ImportException;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * ISO 20022 camt.053 (Bank-to-Customer Statement), Namespaces camt.053.001.02 und camt.053.001.08.
 * Nur lesend für die Anzeige. IBANs werden ausschließlich maskiert zurückgegeben.
 * Externe Entitäten und DOCTYPE werden abgelehnt (XXE-Schutz).
 */
final class Camt053Parser
{
    /** @var array<string, string> */
    public const array NAMESPACES = [
        'v02' => 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02',
        'v08' => 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.08',
    ];

    /**
     * @return array<int, CamtEntry>
     */
    public function parse(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new ImportException('camt.053: DOCTYPE oder ENTITY-Deklarationen sind nicht zulässig.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            throw new ImportException('camt.053: XML konnte nicht gelesen werden.');
        }

        $namespace = $document->documentElement->namespaceURI ?? '';
        $version = array_search($namespace, self::NAMESPACES, true);

        if ($version === false) {
            throw new ImportException(sprintf('camt.053: Namespace "%s" wird nicht unterstützt (erwartet 001.02 oder 001.08).', $namespace));
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', $namespace);

        $entries = [];

        foreach ($xpath->query('/c:Document/c:BkToCstmrStmt/c:Stmt') ?: [] as $stmt) {
            if (! $stmt instanceof DOMElement) {
                continue;
            }

            $iban = $this->text($xpath, 'c:Acct/c:Id/c:IBAN', $stmt);
            $ibanMasked = $iban !== null ? BankAccount::maskIban($iban) : '';

            foreach ($xpath->query('c:Ntry', $stmt) ?: [] as $ntry) {
                if (! $ntry instanceof DOMElement) {
                    continue;
                }

                $entries[] = $this->entry($xpath, $ntry, $ibanMasked);
            }
        }

        return $entries;
    }

    public function detectVersion(string $xml): ?string
    {
        foreach (self::NAMESPACES as $version => $namespace) {
            if (str_contains($xml, $namespace)) {
                return $version;
            }
        }

        return null;
    }

    private function entry(DOMXPath $xpath, DOMElement $ntry, string $ibanMasked): CamtEntry
    {
        $amountNode = $xpath->query('c:Amt', $ntry)?->item(0);
        $amountRaw = $amountNode !== null ? $amountNode->textContent : '0';
        $currency = $amountNode instanceof DOMElement ? ($amountNode->getAttribute('Ccy') ?: 'EUR') : 'EUR';
        $cents = $this->toCents($amountRaw);
        $indicator = $this->text($xpath, 'c:CdtDbtInd', $ntry);

        if ($indicator === 'DBIT') {
            $cents = -abs($cents);
        }

        $tx = $xpath->query('c:NtryDtls/c:TxDtls', $ntry)?->item(0);
        $txElement = $tx instanceof DOMElement ? $tx : null;

        $counterpartyName = null;
        $counterpartyIban = null;

        if ($txElement !== null) {
            $side = $indicator === 'DBIT' ? 'Cdtr' : 'Dbtr';
            // v02: Nm direkt unter Cdtr/Dbtr; v08: Cdtr/Pty/Nm
            $counterpartyName = $this->text($xpath, "c:RltdPties/c:{$side}/c:Nm", $txElement)
                ?? $this->text($xpath, "c:RltdPties/c:{$side}/c:Pty/c:Nm", $txElement);
            $counterpartyIban = $this->text($xpath, "c:RltdPties/c:{$side}Acct/c:Id/c:IBAN", $txElement);
        }

        $remittance = null;

        if ($txElement !== null) {
            $parts = [];
            foreach ($xpath->query('c:RmtInf/c:Ustrd', $txElement) ?: [] as $ustrd) {
                $parts[] = trim($ustrd->textContent);
            }
            $remittance = $parts === [] ? null : implode(' ', $parts);
        }

        $remittance ??= $this->text($xpath, 'c:AddtlNtryInf', $ntry);

        return new CamtEntry(
            accountIbanMasked: $ibanMasked,
            amountCents: $cents,
            currency: $currency,
            bookingDate: $this->date($this->text($xpath, 'c:BookgDt/c:Dt', $ntry) ?? $this->text($xpath, 'c:BookgDt/c:DtTm', $ntry)),
            valueDate: $this->date($this->text($xpath, 'c:ValDt/c:Dt', $ntry) ?? $this->text($xpath, 'c:ValDt/c:DtTm', $ntry)),
            remittanceInfo: $remittance !== null ? mb_substr($remittance, 0, 500) : null,
            counterpartyName: $counterpartyName,
            counterpartyIbanMasked: $counterpartyIban !== null ? BankAccount::maskIban($counterpartyIban) : null,
            endToEndId: $txElement !== null ? $this->text($xpath, 'c:Refs/c:EndToEndId', $txElement) : null,
            acctSvcrRef: $this->text($xpath, 'c:AcctSvcrRef', $ntry) ?? ($txElement !== null ? $this->text($xpath, 'c:Refs/c:AcctSvcrRef', $txElement) : null),
            status: $this->text($xpath, 'c:Sts', $ntry) ?? $this->text($xpath, 'c:Sts/c:Cd', $ntry),
        );
    }

    private function text(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        $node = $xpath->query($query, $context)?->item(0);

        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function toCents(string $amount): int
    {
        $clean = trim($amount);

        if (preg_match('/^-?\d+(\.\d+)?$/', $clean) !== 1) {
            return 0;
        }

        $negative = str_starts_with($clean, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($clean, '-'), 2), 2, '0');
        $cents = ((int) $whole) * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }
}
