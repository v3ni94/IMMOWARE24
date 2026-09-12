<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Http\Controllers;

use App\Modules\Gmail\Services\Push\GoogleIdTokenVerifier;
use App\Modules\Gmail\Services\Push\PushEventService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /mail/gmail/push: Pub/Sub-Push-Zustellung (Pfad ohne api/, siehe routes/modules/gmail.php). Antwortet erst nach Speichern und Einplanen des
 * HistorySyncJob mit 204. Duplikate (pubsub_message_id) und unbekannte Postfächer antworten ebenfalls 204
 * (ohne Wirkung, protokolliert), damit Pub/Sub nicht endlos erneut zustellt. Ungültige Nutzlast: 400. Fehlt das
 * Prüfergebnis der Middleware mail.push.auth im Request oder ist es nicht ok, antwortet der Controller selbst 401.
 */
final class PushController
{
    public function __construct(private readonly PushEventService $events) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();
        $authResult = $request->attributes->get('mail_push_auth_result');

        // Ohne Ergebnis der Middleware mail.push.auth (Routenänderung, Fehlkonfiguration) gilt nichts als authentifiziert.
        if ($authResult !== GoogleIdTokenVerifier::RESULT_OK) {
            abort(401, 'Pub/Sub-Push nicht authentifiziert.');
        }

        $authResult = (string) $authResult;

        $outcome = $this->events->handle(is_array($payload) ? $payload : [], $authResult);

        if ($outcome === PushEventService::OUTCOME_INVALID) {
            return response('', 400);
        }

        return response('', 204);
    }
}
