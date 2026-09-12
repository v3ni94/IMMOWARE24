<?php

declare(strict_types=1);

namespace App\Modules\Cases\Exceptions;

use DomainException;

/**
 * Abschlussbedingungen nicht erfüllt. $unmet enthält die offenen Bedingungen im Klartext.
 */
final class CaseNotClosableException extends DomainException
{
    /**
     * @param  array<int, string>  $unmet
     */
    public function __construct(public readonly array $unmet)
    {
        parent::__construct('Vorgang kann nicht abgeschlossen werden: '.implode(' ', $unmet));
    }
}
