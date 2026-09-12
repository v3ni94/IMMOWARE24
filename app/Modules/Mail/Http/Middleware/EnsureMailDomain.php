<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zweite Hostprüfung (Alias mail.domain) neben Route::domain(): Anfragen an Mail-Routen über einen anderen Host
 * erhalten 404 (docs/mail/01-architekturentscheidung.md, Abschnitt 3).
 */
final class EnsureMailDomain
{
    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $expected = strtolower(trim((string) $this->config->get('hub.mail.domain', '')));

        if ($expected !== '' && strtolower($request->getHost()) !== $expected) {
            abort(404);
        }

        return $next($request);
    }
}
