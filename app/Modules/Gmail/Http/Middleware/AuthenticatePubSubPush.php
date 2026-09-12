<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Http\Middleware;

use App\Modules\Gmail\Services\Push\GoogleIdTokenVerifier;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias mail.push.auth: prüft das OIDC-Token der Pub/Sub-Push-Zustellung (Authorization: Bearer) und optional das
 * eigene Pfad-Token. Ohne konfiguriertes Topic antwortet der Endpunkt 404 (Nicht eingerichtet). Abgelehnte Anfragen
 * erhalten 401 oder 403 und werden nie als Erfolg gezählt; das Ergebnis der Prüfung wird für die Protokollierung
 * im Request abgelegt (attribute mail_push_auth_result).
 */
final class AuthenticatePubSubPush
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (trim((string) $this->config->get('hub.gmail.push.topic', '')) === '') {
            abort(404);
        }

        $pathToken = trim((string) $this->config->get('hub.gmail.push.path_token', ''));

        if ($pathToken !== '' && ! hash_equals($pathToken, (string) $request->route('token', $request->query('token', '')))) {
            Log::warning('Gmail Push: Pfad-Token stimmt nicht.', ['ip' => $request->ip()]);

            return response()->json(['error' => 'token_mismatch'], 403);
        }

        $verification = $this->verifier->verify($request->bearerToken());
        $request->attributes->set('mail_push_auth_result', $verification['result']);
        $request->attributes->set('mail_push_claims', $verification['claims']);

        if ($verification['result'] !== GoogleIdTokenVerifier::RESULT_OK) {
            Log::warning('Gmail Push: OIDC-Prüfung abgelehnt.', ['result' => $verification['result'], 'ip' => $request->ip()]);

            return response()->json(['error' => $verification['result']], $verification['result'] === GoogleIdTokenVerifier::RESULT_MISSING ? 401 : 403);
        }

        return $next($request);
    }
}
