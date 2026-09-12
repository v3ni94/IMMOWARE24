<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Exceptions;

use RuntimeException;

/**
 * Schreibender Aufruf ohne Antwort (Timeout). Ergebnis unklar: erst nachlesen, dann entscheiden.
 */
final class LexwareTimeoutException extends RuntimeException {}
