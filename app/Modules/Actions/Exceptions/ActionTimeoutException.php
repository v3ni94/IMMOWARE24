<?php

declare(strict_types=1);

namespace App\Modules\Actions\Exceptions;

use RuntimeException;

/**
 * Externer Aufruf ohne verwertbare Antwort (Timeout, Verbindungsabbruch). Ergebnis ist unklar: erst nachlesen,
 * nie blind wiederholen.
 */
final class ActionTimeoutException extends RuntimeException {}
