<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Middleware;

use App\Modules\Security\Http\ProblemResponse;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate Limit je API-Key (Standard 60 Requests pro Minute, konfigurierbar oder als Parameter).
 */
final class ThrottleApiKey
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, ?string $maxPerMinute = null): Response
    {
        $apiKey = AuthenticateApiKey::fromRequest($request);

        if ($apiKey === null) {
            return ProblemResponse::unauthenticated(detail: 'Kein authentifizierter API-Key im Request.');
        }

        $limit = $maxPerMinute !== null ? (int) $maxPerMinute : (int) config('hub.security.api_keys.rate_limit_per_minute', 60);
        $bucket = 'api-key:'.$apiKey->getKey();

        if ($this->limiter->tooManyAttempts($bucket, $limit)) {
            return ProblemResponse::rateLimited($this->limiter->availableIn($bucket), $limit);
        }

        $this->limiter->hit($bucket, 60);

        $response = $next($request);
        $remaining = max(0, $limit - $this->limiter->attempts($bucket));

        $response->headers->set('RateLimit-Limit', (string) $limit);
        $response->headers->set('RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
