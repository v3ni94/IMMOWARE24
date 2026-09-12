<?php

declare(strict_types=1);

namespace App\Modules\Security\Http;

use Illuminate\Http\JsonResponse;

/**
 * Fehlerantworten als application/problem+json (RFC 9457) gemäß 09-api-documentation.md.
 */
final class ProblemResponse
{
    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, string $code, string $title, ?string $detail = null, array $extensions = [], array $headers = []): JsonResponse
    {
        $payload = array_merge([
            'type' => 'https://immoware.muellerhv.de/problems/'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
        ], $detail !== null ? ['detail' => $detail] : [], $extensions);

        return new JsonResponse($payload, $status, array_merge(['Content-Type' => 'application/problem+json'], $headers));
    }

    public static function unauthenticated(string $code = 'unauthenticated', ?string $detail = null): JsonResponse
    {
        return self::make(401, $code, 'Nicht authentifiziert', $detail, [], ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $code, ?string $detail = null): JsonResponse
    {
        return self::make(403, $code, 'Zugriff verweigert', $detail);
    }

    public static function rateLimited(int $retryAfter, int $limit): JsonResponse
    {
        return self::make(429, 'rate_limited', 'Zu viele Anfragen', 'Das Anfragelimit dieses API-Keys ist erreicht.', [], [
            'Retry-After' => (string) $retryAfter,
            'RateLimit-Limit' => (string) $limit,
            'RateLimit-Remaining' => '0',
            'RateLimit-Reset' => (string) $retryAfter,
        ]);
    }
}
