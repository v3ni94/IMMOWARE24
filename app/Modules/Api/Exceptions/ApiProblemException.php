<?php

declare(strict_types=1);

namespace App\Modules\Api\Exceptions;

use App\Core\Exceptions\HubException;
use App\Modules\Api\Http\Problem;
use Illuminate\Http\JsonResponse;

/**
 * Fachlicher Fehler, der als application/problem+json ausgegeben wird.
 */
final class ApiProblemException extends HubException
{
    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $problemCode);
    }

    public function toResponse(): JsonResponse
    {
        return Problem::make($this->status, $this->problemCode, $this->detail, $this->extensions, $this->headers);
    }

    public static function badRequest(string $code, string $detail): self
    {
        return new self(400, $code, $detail);
    }

    public static function forbidden(string $code, string $detail): self
    {
        return new self(403, $code, $detail);
    }

    public static function conflict(string $code, string $detail): self
    {
        return new self(409, $code, $detail);
    }

    public static function notImplemented(string $detail, string $hint): self
    {
        return new self(501, 'not_implemented', $detail, ['hint' => $hint]);
    }
}
