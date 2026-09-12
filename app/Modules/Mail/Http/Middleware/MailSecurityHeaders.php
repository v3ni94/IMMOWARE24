<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zweite Sperre hinter dem HtmlSanitizer (docs/mail/08): Content-Security-Policy für alle Antworten der Mail-Routen,
 * unabhängig von nginx. Sanitisiertes Mail-HTML wird inline gerendert; Skripte nur von der eigenen Herkunft
 * (hub.js, mail.js), keine Inline-Skripte, keine Plugins, keine Einbettung, keine fremden Formularziele.
 */
final class MailSecurityHeaders
{
    public const string POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: cid:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
