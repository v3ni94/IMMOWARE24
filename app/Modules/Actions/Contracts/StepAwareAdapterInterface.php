<?php

declare(strict_types=1);

namespace App\Modules\Actions\Contracts;

use App\Core\Contracts\Mail\TargetSystemAdapterInterface;
use App\Modules\Actions\DTO\StepResult;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\Execution;

/**
 * Modulinterne Erweiterung des Core-Vertrags um schrittweise Ausführung und Verifikation. Die Engine ruft je
 * Planschritt genau einen Adapter; der Core-Vertrag bleibt unverändert und wird durch Delegation bedient.
 * Offen: Übernahme der Schrittmethoden in den Core-Vertrag (app/Core ist in diesem Abschnitt nicht anzufassen).
 */
interface StepAwareAdapterInterface extends TargetSystemAdapterInterface
{
    /**
     * Prüft einen Schritt vor Freigabe (Allowlist des Adapters, Referenz, Felder). Liefert Warnungen, wirft bei Sperre.
     *
     * @param  array<string, mixed>  $step
     * @return array<int, string>
     */
    public function validateStep(array $step): array;

    /**
     * Externer Aufruf für genau einen Schritt. Läuft außerhalb der lokalen Transaktion.
     */
    public function executeStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): StepResult;

    /**
     * Liest den Zielzustand nach der Ausführung erneut. Rückgabe status ist VerificationStatus als Wert.
     *
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>, method: string}
     */
    public function verifyStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): array;
}
