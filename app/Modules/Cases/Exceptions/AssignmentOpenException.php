<?php

declare(strict_types=1);

namespace App\Modules\Cases\Exceptions;

use DomainException;

/**
 * Sensible Änderung bei mehrdeutiger Zuordnung (Status assignment_open) gesperrt.
 */
final class AssignmentOpenException extends DomainException {}
