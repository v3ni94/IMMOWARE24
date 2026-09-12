<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/**
 * Die KI-Antwort verletzt das Schema oder eine Fachregel. Die Antwort wird verworfen, es entsteht kein Vorschlag.
 *
 * @property array<int, string> $errors
 */
final class AiSchemaException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(public readonly array $errors, public readonly bool $businessRule = false)
    {
        parent::__construct(($businessRule ? 'Fachregel verletzt: ' : 'Schemaverletzung: ').implode('; ', $errors));
    }
}
