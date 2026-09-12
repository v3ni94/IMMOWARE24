<?php

declare(strict_types=1);

namespace App\Core\Contracts\Mail;

/**
 * Interner Adaptervertrag für ein Zielsystem einer Aktion (Immoware24, Lexware, Gmail, Drive, manuell).
 * Keine Herstellerendpunkte. Reihenfolge der Prüfung: Flag, Konfiguration, Recht, Vier-Augen-Freigabe,
 * Ausführung, Verifikation. Eine Ausführung ohne Verifikation ist kein Geschäftsergebnis.
 *
 * Objektparameter sind bewusst als object typisiert: die Module liefern ihre Models
 * (App\Modules\Actions\Models\ActionPlan, ActionPlanVersion, Approval, Execution). Der Vertrag bleibt frei
 * von Modulklassen, damit Core keine Modulabhängigkeit erhält.
 */
interface TargetSystemAdapterInterface
{
    /**
     * Zielsystem gemäß App\Modules\Actions\Enums\TargetSystem.
     */
    public function targetSystem(): string;

    /**
     * Fähigkeiten des Zielsystems: action_key => ['status' => available|restricted|unavailable|not_configured, 'reason' => ?string].
     * Für Immoware24 aus der bestehenden CapabilityRegistry, nie erfunden.
     *
     * @return array<string, array{status: string, reason: ?string}>
     */
    public function capabilities(): array;

    /**
     * Liest den aktuellen Zustand einer Referenz (z. B. Kontakt) nach. Grundlage für Alt/Neu und Verifikation.
     *
     * @param  array{reference_type: string, external_id: string, local_id?: ?int}  $ref
     * @return array<string, mixed>
     */
    public function readCurrent(array $ref): array;

    /**
     * Bereitet eine Änderung vor (Validierung gegen capabilities(), Alt/Neu, erwartetes Ergebnis). Kein Schreibzugriff.
     *
     * @param  object  $plan  App\Modules\Actions\Models\ActionPlan
     * @return array{steps: array<int, array<string, mixed>>, risk_class: string, approval_required: bool, warnings: array<int, string>}
     */
    public function prepareChange(object $plan): array;

    /**
     * Führt eine freigegebene Planversion aus. Idempotent über execution.idempotency_key. HTTP-Erfolg liefert
     * status executed, nie verified.
     *
     * @param  object  $planVersion  App\Modules\Actions\Models\ActionPlanVersion
     * @param  object  $approval  App\Modules\Actions\Models\Approval
     * @return array<int, array{step_index: int, status: string, response_status: ?int, execution_uuid: string}>
     */
    public function executeApprovedChange(object $planVersion, object $approval): array;

    /**
     * Verifiziert eine Ausführung durch Nachlesen. Rückgabe ist App\Modules\Actions\Enums\VerificationStatus als Wert.
     *
     * @param  object  $execution  App\Modules\Actions\Models\Execution
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>}
     */
    public function verifyChange(object $execution): array;
}
