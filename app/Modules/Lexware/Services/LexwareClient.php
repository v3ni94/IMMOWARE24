<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Services;

use App\Core\Support\SecretMasker;
use App\Modules\Lexware\DTO\LexwareCredentials;
use App\Modules\Lexware\Exceptions\LexwareConflictException;
use App\Modules\Lexware\Exceptions\LexwareTimeoutException;
use App\Modules\Lexware\Exceptions\LexwareUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * HTTP-Client für die Lexware Office Public API über die Http-Facade (Http::fake-fähig). Bearer API-Key, zentraler
 * Token-Bucket je Zugang, 429 mit Retry-After, Pagination, optimistic locking über version (409 = Konflikt).
 * Alle Endpunkt-Pfade: aus Snippets, vor Produktivbetrieb am Original prüfen (docs/mail/research).
 * Kein Anlegen von Kunden, keine Rechnungs- oder Belegänderungen: dieser Client kennt nur contacts.get/filter/update.
 */
final class LexwareClient
{
    // aus Snippets, vor Produktivbetrieb am Original prüfen: GET /v1/contacts/{id}
    public const string PATH_CONTACT = 'contacts/%s';

    // aus Snippets, vor Produktivbetrieb am Original prüfen: GET /v1/contacts?email=&name=&customer=true&page=&size=
    public const string PATH_CONTACTS = 'contacts';

    /** @var array<int, string> Filterparameter laut Snippets (email, name, number, customer, vendor) */
    public const array FILTERS = ['email', 'name', 'number', 'customer', 'vendor'];

    public const int PAGE_SIZE = 25;

    public const int MAX_PAGES = 40;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LexwareRateLimiter $limiter,
        private readonly SecretMasker $masker,
        private readonly LexwareCredentials $credentials,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxRateLimitRetries = 2,
    ) {}

    public function credentials(): LexwareCredentials
    {
        return $this->credentials;
    }

    /**
     * @return array<string, mixed> Kontakt inkl. version; null bei 404
     */
    public function getContact(string $id): ?array
    {
        $response = $this->send('GET', sprintf(self::PATH_CONTACT, rawurlencode($id)));

        if ($response->status() === 404) {
            return null;
        }

        $this->assertReadable($response);

        return (array) $response->json();
    }

    /**
     * Vollständige Suche über alle Seiten. Rückgabe nur, wenn jede Seite erfolgreich gelesen wurde; sonst Exception
     * (unvollständige Suche darf nie als "kein Treffer" gelten).
     *
     * @param  array<string, scalar>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function filterContacts(array $filters): array
    {
        $query = array_intersect_key($filters, array_flip(self::FILTERS));
        $query['size'] = self::PAGE_SIZE;
        $results = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query['page'] = $page;
            $response = $this->send('GET', self::PATH_CONTACTS, $query);
            $this->assertReadable($response);
            $body = (array) $response->json();
            $content = (array) ($body['content'] ?? []);

            foreach ($content as $contact) {
                if (is_array($contact)) {
                    $results[] = $contact;
                }
            }

            // aus Snippets: Seitenantwort mit content, totalPages, last (Spring-Pagination). Am Original prüfen.
            $last = (bool) ($body['last'] ?? ($page + 1 >= (int) ($body['totalPages'] ?? 1)));

            if ($last || $content === []) {
                return $results;
            }
        }

        throw new LexwareUnavailableException('Kontaktsuche unvollständig: Seitenlimit erreicht.');
    }

    /**
     * PUT mit optimistic locking: payload muss die frisch gelesene version tragen. 409 wird als Konflikt geworfen.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateContact(string $id, array $payload): array
    {
        if (! isset($payload['version'])) {
            throw new \InvalidArgumentException('Kontaktänderung ohne version ist nicht zulässig (optimistic locking).');
        }

        try {
            $response = $this->send('PUT', sprintf(self::PATH_CONTACT, rawurlencode($id)), null, $payload);
        } catch (LexwareTimeoutException $e) {
            throw $e;
        }

        if ($response->status() === 409) {
            throw new LexwareConflictException('Lexware meldet Versionskonflikt (409): Kontakt wurde zwischenzeitlich geändert.');
        }

        if ($response->status() === 404) {
            throw new LexwareUnavailableException('Kontakt existiert in Lexware nicht (404).', 404);
        }

        if ($response->status() === 400 || $response->status() === 406) {
            throw new LexwareUnavailableException('Lexware lehnt die Änderung ab ('.$response->status().'): '.$this->excerpt($response), $response->status());
        }

        $this->assertReadable($response);

        return (array) $response->json();
    }

    /**
     * @param  array<string, scalar>|null  $query
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $method, string $path, ?array $query = null, ?array $body = null): Response
    {
        $url = rtrim($this->credentials->baseUrl, '/').'/'.ltrim($path, '/');
        $attempt = 0;

        while (true) {
            if (! $this->limiter->acquire($this->credentials->rateLimitKey())) {
                throw new LexwareUnavailableException('Lexware-Rate-Limit lokal erreicht, kein Slot verfügbar.');
            }

            try {
                $response = $this->request()->send($method, $url, array_filter(['query' => $query, 'json' => $body], static fn (mixed $v): bool => $v !== null));
            } catch (ConnectionException $e) {
                if ($method !== 'GET') {
                    throw new LexwareTimeoutException('Keine Antwort von Lexware auf '.$method.' (Timeout oder Verbindungsabbruch). Ergebnis unklar.');
                }

                throw new LexwareUnavailableException('Lexware nicht erreichbar: '.class_basename($e));
            }

            if ($response->status() === 429 && $attempt < $this->maxRateLimitRetries) {
                $retryAfter = max(1, (int) ($response->header('Retry-After') ?: 1));
                Log::info('Lexware 429, warte Retry-After.', ['seconds' => $retryAfter, 'attempt' => $attempt + 1]);
                Sleep::for($retryAfter)->seconds();
                $attempt++;

                continue;
            }

            return $response;
        }
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->withToken($this->credentials->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(10, $this->timeoutSeconds));
    }

    private function assertReadable(Response $response): void
    {
        $status = $response->status();

        if ($status === 429) {
            throw new LexwareUnavailableException('Lexware-Rate-Limit (429) auch nach Wartezeit.', 429);
        }

        if ($status === 401 || $status === 403) {
            throw new LexwareUnavailableException('Lexware-Zugang abgelehnt ('.$status.'). Zugang prüfen, Ergebnis ungeklärt.', $status);
        }

        if ($status >= 500) {
            throw new LexwareUnavailableException('Lexware-Serverfehler ('.$status.').', $status);
        }

        if (! $response->successful()) {
            throw new LexwareUnavailableException('Lexware-Antwort nicht verwertbar ('.$status.'): '.$this->excerpt($response), $status);
        }
    }

    private function excerpt(Response $response): string
    {
        return mb_substr($this->masker->maskString($response->body()), 0, 300);
    }
}
