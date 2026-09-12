<?php

declare(strict_types=1);

namespace App\Modules\Sla\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Altbestandsregel (docs/mail/04-status-und-sla.md Abschnitt 8): Nachrichten, deren Empfang vor mail_mailboxes.import_from
 * liegt oder beim Import älter als hub.sla.legacy_after_days ist, erhalten keine laufenden SLA-Uhren. Die Uhren werden
 * als cancelled mit Hinweis "Altbestand" angelegt, damit die Verzugsanzeige nicht künstlich rot wird. Verantwortlicher,
 * nächster Schritt und Fälligkeit bleiben Pflicht und werden von Menschen gesetzt.
 */
final class LegacyImportPolicy
{
    public const string SOURCE = 'legacy_import';

    public function __construct(private readonly Repository $config) {}

    public function legacyAfterDays(): int
    {
        return max(0, (int) $this->config->get('hub.sla.legacy_after_days', 14));
    }

    /**
     * Begründung, wenn die Nachricht Altbestand ist, sonst null. $importedAt ist der Importzeitpunkt (Fallback jetzt).
     */
    public function legacyReason(CarbonImmutable $receivedAt, ?CarbonImmutable $importFrom, ?CarbonImmutable $importedAt = null): ?string
    {
        if ($importFrom instanceof CarbonImmutable && $receivedAt->lessThan($importFrom)) {
            return sprintf('Altbestand: Empfang %s liegt vor dem Importbeginn des Postfachs (%s).', $receivedAt->utc()->toIso8601String(), $importFrom->utc()->toIso8601String());
        }

        $days = $this->legacyAfterDays();

        if ($days <= 0) {
            return null;
        }

        $reference = $importedAt ?? CarbonImmutable::now();

        if ($receivedAt->lessThanOrEqualTo($reference->subDays($days))) {
            return sprintf('Altbestand: Empfang %s liegt beim Import mindestens %d Tage zurück.', $receivedAt->utc()->toIso8601String(), $days);
        }

        return null;
    }

    public function isLegacy(CarbonImmutable $receivedAt, ?CarbonImmutable $importFrom, ?CarbonImmutable $importedAt = null): bool
    {
        return $this->legacyReason($receivedAt, $importFrom, $importedAt) !== null;
    }
}
