<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Exceptions;

use RuntimeException;

/**
 * HTTP 409: Version des Kontakts hat sich geändert (optimistic locking). Kein Erfolg, manuelle Prüfung.
 */
final class LexwareConflictException extends RuntimeException {}
