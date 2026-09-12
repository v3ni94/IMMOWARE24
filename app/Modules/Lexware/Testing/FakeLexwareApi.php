<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Http::fake-Builder für die Lexware Office Public API. Führt Kontakte mit Version, beantwortet GET, PUT (mit
 * Versionsprüfung, 409 bei Konflikt), Suche und liefert wahlweise Fehlerantworten. Antwortformen sind aus Snippets
 * rekonstruiert (docs/mail/research) und vor Implementierung am Original zu prüfen. Kein Live-Test.
 */
final class FakeLexwareApi
{
    /** @var array<string, array<string, mixed>> */
    private array $contacts = [];

    /** @var array<string, int> Pfadmuster => HTTP-Status, der beim nächsten Treffer zurückgegeben wird */
    private array $failures = [];

    /** @var array<int, array{method: string, pattern: string, status: int}> Methodenspezifische Fehler (einmalig) */
    private array $methodFailures = [];

    /** @var array<int, array{method: string, pattern: string, apply: bool}> Simulierte Timeouts (einmalig) */
    private array $timeouts = [];

    /** @var array<int, array{method: string, path: string}> Protokoll aller Anfragen */
    private array $requests = [];

    private int $rateLimitRemaining = PHP_INT_MAX;

    public function __construct(private readonly string $baseUrl = 'https://api.lexoffice.io/v1') {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function seedContact(string $id, array $attributes = [], int $version = 1): self
    {
        $this->contacts[$id] = ['id' => $id, 'version' => $version] + $attributes;

        return $this;
    }

    public function failNext(string $pathPattern, int $status): self
    {
        $this->failures[$pathPattern] = $status;

        return $this;
    }

    /**
     * Fehlerantwort nur für eine HTTP-Methode (z. B. PUT 500, GET bleibt lesbar).
     */
    public function failNextMethod(string $method, string $pathPattern, int $status): self
    {
        $this->methodFailures[] = ['method' => strtoupper($method), 'pattern' => $pathPattern, 'status' => $status];

        return $this;
    }

    /**
     * Simuliert einen Timeout: mit $applyChange wird ein PUT serverseitig ausgeführt, die Antwort geht aber verloren
     * (ConnectionException). Genau der Fall "Ergebnis unklar".
     */
    public function timeoutNext(string $method, string $pathPattern, bool $applyChange = true): self
    {
        $this->timeouts[] = ['method' => strtoupper($method), 'pattern' => $pathPattern, 'apply' => $applyChange];

        return $this;
    }

    /**
     * @return array<int, array{method: string, path: string}>
     */
    public function requests(?string $method = null): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $method === null || $r['method'] === strtoupper($method)));
    }

    public function limitRequests(int $allowed): self
    {
        $this->rateLimitRemaining = $allowed;

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function contacts(): array
    {
        return $this->contacts;
    }

    /**
     * Registriert Http::fake() für alle Pfade unter base_url. Rückgabe erlaubt Http::assertSent()-Prüfungen im Test.
     */
    public function install(): void
    {
        Http::fake([
            rtrim($this->baseUrl, '/').'/*' => fn (Request $request): PromiseInterface => $this->handle($request),
        ]);
    }

    private function handle(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $relative = trim(substr($path, strlen((string) parse_url($this->baseUrl, PHP_URL_PATH))), '/');
        $this->requests[] = ['method' => $request->method(), 'path' => $relative];

        foreach ($this->methodFailures as $index => $failure) {
            if ($failure['method'] === $request->method() && fnmatch($failure['pattern'], $relative)) {
                unset($this->methodFailures[$index]);

                return Http::response(['message' => 'Fake-Fehler'], $failure['status']);
            }
        }

        foreach ($this->timeouts as $index => $timeout) {
            if ($timeout['method'] === $request->method() && fnmatch($timeout['pattern'], $relative)) {
                unset($this->timeouts[$index]);

                if ($timeout['apply'] && $request->method() === 'PUT' && preg_match('#^contacts/([^/]+)$#', $relative, $tm) === 1 && isset($this->contacts[$tm[1]])) {
                    $body = (array) $request->data();
                    $updated = $body + $this->contacts[$tm[1]];
                    $updated['version'] = (int) $this->contacts[$tm[1]]['version'] + 1;
                    $this->contacts[$tm[1]] = $updated;
                }

                throw new ConnectionException('Fake: cURL error 28: Operation timed out');
            }
        }

        foreach ($this->failures as $pattern => $status) {
            if (fnmatch($pattern, $relative)) {
                unset($this->failures[$pattern]);

                return Http::response(['message' => 'Fake-Fehler'], $status);
            }
        }

        if ($this->rateLimitRemaining !== PHP_INT_MAX) {
            if ($this->rateLimitRemaining <= 0) {
                return Http::response(['message' => 'Rate limit exceeded'], 429, ['Retry-After' => '1']);
            }

            $this->rateLimitRemaining--;
        }

        if (! $request->hasHeader('Authorization')) {
            return Http::response(['message' => 'Unauthorized'], 401);
        }

        if (preg_match('#^contacts/([^/]+)$#', $relative, $m) === 1) {
            $id = $m[1];
            $contact = $this->contacts[$id] ?? null;

            if ($contact === null) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            if ($request->method() === 'GET') {
                return Http::response($contact, 200);
            }

            if ($request->method() === 'PUT') {
                $body = (array) $request->data();

                if ((int) ($body['version'] ?? -1) !== (int) $contact['version']) {
                    return Http::response(['message' => 'Version conflict'], 409);
                }

                $updated = $body + $contact;
                $updated['version'] = (int) $contact['version'] + 1;
                $this->contacts[$id] = $updated;

                return Http::response(['id' => $id, 'resourceUri' => $this->baseUrl.'/contacts/'.$id, 'version' => $updated['version']], 200);
            }
        }

        if ($relative === 'contacts' && $request->method() === 'GET') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $needle = strtolower((string) ($query['email'] ?? $query['name'] ?? ''));
            $content = array_values(array_filter($this->contacts, static function (array $contact) use ($needle): bool {
                return $needle === '' || str_contains(strtolower(json_encode($contact, JSON_THROW_ON_ERROR)), $needle);
            }));

            return Http::response(['content' => $content, 'totalElements' => count($content), 'page' => 0, 'size' => 25], 200);
        }

        return Http::response(['message' => 'Fake: Pfad nicht abgebildet'], 404);
    }
}
