<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Core\Support\CorrelationId;
use App\Modules\Api\Http\Problem;
use App\Modules\Api\Models\IdempotencyKey;
use App\Modules\Api\Support\ApiCaller;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Header Idempotency-Key ist für POST und PATCH Pflicht. Eine Wiederholung mit gleichem Schlüssel und
 * gleichem Body liefert die gespeicherte Antwort (Header Idempotent-Replayed: true), ein abweichender
 * Body 409 idempotency_mismatch. Antworten werden 24 Stunden vorgehalten.
 *
 * Vor der Controller-Ausführung wird ein In-Progress-Marker atomar gesetzt (Cache::add). Ein paralleler
 * Zweitaufruf mit demselben Schlüssel erhält 409 idempotency_in_progress, statt den Seiteneffekt zu wiederholen.
 */
final class IdempotencyMiddleware
{
    public const string REPLAY_HEADER = 'Idempotent-Replayed';

    /** Lebensdauer des In-Progress-Markers in Sekunden (Obergrenze für einen hängenden Request). */
    public const int IN_PROGRESS_TTL_SECONDS = 120;

    public function __construct(
        private readonly ApiCaller $caller,
        private readonly CorrelationId $correlationId,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PATCH', 'PUT'], true)) {
            return $next($request);
        }

        $headerName = (string) config('hub.api.idempotency.header', 'Idempotency-Key');
        $key = trim((string) $request->headers->get($headerName, ''));

        if ($key === '') {
            return Problem::make(400, 'idempotency_key_required', sprintf('Der Header %s ist für schreibende Anfragen Pflicht.', $headerName));
        }

        if (strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            return Problem::make(400, 'idempotency_key_invalid', sprintf('Der Header %s darf höchstens 128 Zeichen aus A-Z, a-z, 0-9, Punkt, Doppelpunkt, Unterstrich und Bindestrich enthalten.', $headerName));
        }

        $apiKeyId = $this->caller->apiKeyId($request);
        $requestHash = $this->requestHash($request);
        $path = '/'.ltrim($request->path(), '/');

        /** @var IdempotencyKey|null $stored */
        $stored = IdempotencyKey::query()
            ->where('api_key_id', $apiKeyId)
            ->where('idempotency_key', $key)
            ->where('method', $request->method())
            ->where('path', $path)
            ->first();

        if ($stored !== null) {
            $expires = $stored->getAttribute('expires_at');

            if ($expires instanceof CarbonImmutable && $expires->isPast()) {
                $stored->delete();
                $stored = null;
            }
        }

        if ($stored !== null) {
            if (! hash_equals((string) $stored->getAttribute('request_hash'), $requestHash)) {
                return Problem::make(409, 'idempotency_mismatch', 'Der Idempotency-Key wurde bereits mit einem abweichenden Anfrageinhalt verwendet.');
            }

            if ($stored->getAttribute('response_status') === null) {
                return Problem::make(409, 'idempotency_in_progress', 'Eine Anfrage mit diesem Idempotency-Key wird gerade verarbeitet.');
            }

            $headers = (array) $stored->getAttribute('response_headers');
            $headers[self::REPLAY_HEADER] = 'true';

            return new HttpResponse((string) $stored->getAttribute('response_body'), (int) $stored->getAttribute('response_status'), $headers);
        }

        $marker = self::inProgressKey($apiKeyId, $request->method(), $path, $key);

        if (! Cache::add($marker, $requestHash, self::IN_PROGRESS_TTL_SECONDS)) {
            return Problem::make(409, 'idempotency_in_progress', 'Eine Anfrage mit diesem Idempotency-Key wird gerade verarbeitet.');
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            Cache::forget($marker);

            throw $e;
        }

        if ($response->getStatusCode() < 500 && ($response instanceof JsonResponse || $response instanceof HttpResponse)) {
            IdempotencyKey::query()->create([
                'organization_id' => $this->caller->organizationId($request),
                'api_key_id' => $apiKeyId,
                'idempotency_key' => $key,
                'method' => $request->method(),
                'path' => $path,
                'request_hash' => $requestHash,
                'response_status' => $response->getStatusCode(),
                'response_headers' => $this->storableHeaders($response),
                'response_body' => (string) $response->getContent(),
                'correlation_id' => $this->correlationId->current(),
                'expires_at' => CarbonImmutable::now()->addHours((int) config('hub.api.idempotency.ttl_hours', 24)),
            ]);
        }

        Cache::forget($marker);
        $response->headers->set(self::REPLAY_HEADER, 'false');

        return $response;
    }

    public static function inProgressKey(?int $apiKeyId, string $method, string $path, string $idempotencyKey): string
    {
        return 'api:idempotency:in_progress:'.hash('sha256', ($apiKeyId ?? 0).'|'.$method.'|'.$path.'|'.$idempotencyKey);
    }

    private function requestHash(Request $request): string
    {
        $body = (string) $request->getContent();

        if ($body === '' && $request->allFiles() !== []) {
            $parts = [];

            foreach ($request->allFiles() as $name => $file) {
                if ($file instanceof UploadedFile) {
                    $parts[$name] = hash_file('sha256', $file->getRealPath() ?: $file->getPathname());
                }
            }

            $body = json_encode(['fields' => $request->except(array_keys($request->allFiles())), 'files' => $parts], JSON_THROW_ON_ERROR);
        }

        return hash('sha256', $request->method().' '.$request->path().' '.$body);
    }

    /**
     * @return array<string, string>
     */
    private function storableHeaders(Response $response): array
    {
        $headers = [];

        foreach (['Content-Type', 'Location'] as $name) {
            $value = $response->headers->get($name);

            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
