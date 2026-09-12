<?php

declare(strict_types=1);

namespace App\Modules\Actions\Exceptions;

use RuntimeException;

/**
 * Zielsystem meldet Konflikt (z. B. Lexware 409, Datensatz inzwischen geändert). Nie Erfolg, immer manuelle Prüfung.
 */
final class ActionConflictException extends RuntimeException {}
