<?php

declare(strict_types=1);

namespace App\Modules\Actions\Exceptions;

use RuntimeException;

/**
 * Datensatz ist über die Schnittstelle nicht änderbar (z. B. Lexware-Kontakt mit mehreren Adressen oder Personen).
 * Manueller Klärungsweg, niemals Einträge entfernen.
 */
final class ActionNotEditableException extends RuntimeException {}
