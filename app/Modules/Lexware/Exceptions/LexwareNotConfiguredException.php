<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Exceptions;

use RuntimeException;

/**
 * Kein API-Key hinterlegt: Integration "Nicht eingerichtet".
 */
final class LexwareNotConfiguredException extends RuntimeException {}
