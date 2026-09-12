<?php

declare(strict_types=1);

namespace App\Modules\Api\Http;

use App\Core\Support\CorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fehlerantworten als application/problem+json (RFC 7807 / RFC 9457).
 * type-URLs liegen unter https://immoware.muellerhv.de/errors/<code>.
 */
final class Problem
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /** @var array<int, string> */
    private const array TITLES = [
        400 => 'Ungültige Anfrage',
        401 => 'Nicht authentifiziert',
        403 => 'Zugriff verweigert',
        404 => 'Nicht gefunden',
        405 => 'Methode nicht erlaubt',
        409 => 'Konflikt',
        410 => 'Nicht mehr verfügbar',
        413 => 'Anfrage zu groß',
        415 => 'Medientyp nicht unterstützt',
        422 => 'Validierung fehlgeschlagen',
        423 => 'Gesperrt',
        429 => 'Zu viele Anfragen',
        500 => 'Interner Fehler',
        501 => 'Nicht implementiert',
        503 => 'Dienst nicht verfügbar',
    ];

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, string $code, ?string $detail = null, array $extensions = [], array $headers = [], ?string $instance = null): JsonResponse
    {
        $payload = [
            'type' => self::typeUrl($code),
            'title' => self::TITLES[$status] ?? 'Fehler',
            'status' => $status,
            'code' => $code,
        ];

        if ($detail !== null) {
            $payload['detail'] = $detail;
        }

        $instance ??= self::currentInstance();

        if ($instance !== null) {
            $payload['instance'] = $instance;
        }

        $payload['request_id'] = app(CorrelationId::class)->current();

        foreach ($extensions as $key => $value) {
            $payload[$key] = $value;
        }

        return new JsonResponse($payload, $status, array_merge(['Content-Type' => self::CONTENT_TYPE], $headers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function typeUrl(string $code): string
    {
        return rtrim((string) config('hub.api.problem_base_url', 'https://immoware.muellerhv.de/errors/'), '/').'/'.$code;
    }

    public static function notFound(?string $detail = null): JsonResponse
    {
        return self::make(404, 'not_found', $detail ?? 'Die Ressource existiert nicht oder ist für den Aufrufer nicht sichtbar.');
    }

    public static function unauthenticated(string $code = 'unauthenticated', ?string $detail = null): JsonResponse
    {
        return self::make(401, $code, $detail ?? 'Authorization-Header mit gültigem Bearer-Token erforderlich.', [], ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $code, ?string $detail = null): JsonResponse
    {
        return self::make(403, $code, $detail);
    }

    /**
     * @param  array<string, array<int, string>>  $errors  Laravel-Validierungsfehler je Feld
     */
    public static function validation(array $errors, ?string $detail = null): JsonResponse
    {
        $list = [];

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $list[] = ['field' => (string) $field, 'code' => 'invalid', 'message' => (string) $message];
            }
        }

        return self::make(422, 'validation_failed', $detail ?? ($list[0]['message'] ?? 'Die Anfrage enthält ungültige Felder.'), ['errors' => $list]);
    }

    private static function currentInstance(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? '/'.ltrim($request->path(), '/') : null;
    }
}
