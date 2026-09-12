<?php

declare(strict_types=1);

namespace App\Modules\Api\Exceptions;

use App\Core\Exceptions\CapabilityMissingException;
use App\Core\Exceptions\CircuitOpenException;
use App\Core\Exceptions\RateLimitedException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Api\Http\Problem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Übersetzt Exceptions auf API- und Health-Routen in RFC-7807-Antworten.
 * Wird ausschließlich vom ApiServiceProvider als renderable registriert, nie in bootstrap/app.php.
 */
final class ApiExceptionRenderer
{
    public function handles(Request $request): bool
    {
        return $request->is('api/*') || $request->is('api') || $request->is('health') || $request->is('health/*');
    }

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $this->handles($request)) {
            return null;
        }

        return $this->render($e);
    }

    public function render(Throwable $e): JsonResponse
    {
        if ($e instanceof ApiProblemException) {
            return $e->toResponse();
        }

        if ($e instanceof ValidationException) {
            return Problem::validation($e->errors());
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return Problem::notFound();
        }

        if ($e instanceof AuthenticationException) {
            return Problem::unauthenticated();
        }

        if ($e instanceof AuthorizationException) {
            return Problem::forbidden('role_forbidden', 'Die Aktion ist für den Aufrufer nicht erlaubt.');
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return Problem::make(405, 'method_not_allowed', 'Die Methode ist für diese Ressource nicht erlaubt.', [], $this->stringHeaders($e->getHeaders()));
        }

        if ($e instanceof ThrottleRequestsException) {
            return Problem::make(429, 'rate_limited', 'Das Anfragelimit ist erreicht.', [], $this->stringHeaders($e->getHeaders()));
        }

        if ($e instanceof RateLimitedException) {
            return Problem::make(429, 'rate_limited', 'Das Anfragelimit gegen das Quellsystem ist erreicht.');
        }

        if ($e instanceof CircuitOpenException) {
            return Problem::make(503, 'source_unavailable', 'Das Quellsystem ist derzeit nicht erreichbar.');
        }

        if ($e instanceof WriteBlockedException) {
            return Problem::forbidden('write_disabled', 'Der Schreibpfad ist gesperrt.');
        }

        if ($e instanceof CapabilityMissingException) {
            return Problem::forbidden('capability_locked', 'Die benötigte Fähigkeit ist nicht freigegeben.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                400 => 'bad_request',
                401 => 'unauthenticated',
                403 => 'role_forbidden',
                404 => 'not_found',
                409 => 'conflict',
                413 => 'payload_too_large',
                415 => 'unsupported_media_type',
                422 => 'validation_failed',
                429 => 'rate_limited',
                503 => 'service_unavailable',
                default => 'http_error',
            };

            return Problem::make($status, $code, $status < 500 && $e->getMessage() !== '' ? $e->getMessage() : null, [], $this->stringHeaders($e->getHeaders()));
        }

        Log::error('Unbehandelte Exception auf API-Route', ['exception' => $e::class, 'message' => $e->getMessage()]);

        return Problem::make(500, 'internal_error', 'Es ist ein interner Fehler aufgetreten. Bitte request_id an den Support geben.');
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $result[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $result;
    }
}
