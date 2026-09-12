<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services;

use App\Modules\Gmail\Exceptions\GmailReauthRequiredException;
use App\Modules\Gmail\Services\OAuth\GoogleOAuthService;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-Client für die Gmail REST API (Http-Facade). Zuständig für Authentifizierung (Bearer aus GoogleOAuthService),
 * Quota-Reservierung je Postfach, Fehlerabbildung: 401 einmal Token-Refresh, danach reauth_required; 429 und 5xx
 * werden als vorübergehend (retryable) markiert und über die Backoff-Stufen der Jobs wiederholt; 404 bleibt 404
 * (History-Lücke, gelöschte Nachricht, verschwundener Entwurf). Antwortauszüge werden gekürzt und ohne Secrets geführt.
 * Alle Pfade gemäß Gmail API Referenz, vor Produktivbetrieb am Original prüfen.
 */
final class GmailApiClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
        private readonly GoogleOAuthService $oauth,
        private readonly QuotaCounter $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(Mailbox $mailbox, string $method, string $path, array $query = []): array
    {
        return $this->request($mailbox, $method, 'GET', $path, $query, null);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    public function post(Mailbox $mailbox, string $method, string $path, ?array $json = null, array $query = []): array
    {
        return $this->request($mailbox, $method, 'POST', $path, $query, $json ?? []);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function put(Mailbox $mailbox, string $method, string $path, array $json, array $query = []): array
    {
        return $this->request($mailbox, $method, 'PUT', $path, $query, $json);
    }

    public static function isRetryableStatus(?int $status): bool
    {
        return $status !== null && in_array($status, (array) config('hub.gmail.api.retryable_statuses', [429, 500, 502, 503, 504]), true);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function request(Mailbox $mailbox, string $method, string $verb, string $path, array $query, ?array $json): array
    {
        if (! $this->oauth->isConfigured()) {
            throw MailIntegrationNotConfiguredException::for('gmail');
        }

        $this->quota->reserve((int) $mailbox->getKey(), $method);

        $token = $this->oauth->accessToken($mailbox);
        $response = $this->send($token, $verb, $path, $query, $json);

        if ($response->status() === 401) {
            // Einmal Token erneuern, dann erneut; scheitert das, steht das Postfach auf reauth_required.
            try {
                $token = $this->oauth->accessToken($mailbox, forceRefresh: true);
            } catch (GmailReauthRequiredException $exception) {
                throw $exception;
            }

            $response = $this->send($token, $verb, $path, $query, $json);

            if ($response->status() === 401) {
                $this->oauth->markReauthRequired($mailbox, 'Gmail API lehnt das erneuerte Token ab (401).');

                throw new GmailReauthRequiredException((int) $mailbox->getKey(), 'Gmail API antwortet nach Token-Refresh weiterhin mit 401.');
            }
        }

        if ($response->successful()) {
            $decoded = $response->json();

            return is_array($decoded) ? $decoded : [];
        }

        $status = $response->status();
        $excerpt = $this->excerpt($response);

        if (self::isRetryableStatus($status)) {
            Log::warning('Gmail API: vorübergehender Fehler.', ['mailbox_id' => $mailbox->getKey(), 'method' => $method, 'status' => $status]);
        } else {
            $mailbox->forceFill([
                'last_error_at' => CarbonImmutable::now(),
                'last_error_class' => MailRemoteException::class,
            ])->save();
        }

        throw new MailRemoteException(
            sprintf('Gmail API %s antwortet mit HTTP %d.', $method, $status),
            'gmail',
            $status,
            $excerpt,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $token, string $verb, string $path, array $query, ?array $json): Response
    {
        $url = rtrim((string) $this->config->get('hub.gmail.api.base_url', 'https://gmail.googleapis.com/gmail/v1'), '/').'/'.ltrim($path, '/');

        $pending = $this->http
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) $this->config->get('hub.gmail.api.timeout_seconds', 30))
            ->connectTimeout((int) $this->config->get('hub.gmail.api.connect_timeout_seconds', 10));

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').$this->buildQuery($query);
        }

        try {
            return match ($verb) {
                'POST' => $pending->post($url, $json ?? []),
                'PUT' => $pending->put($url, $json ?? []),
                default => $pending->get($url),
            };
        } catch (ConnectionException $exception) {
            throw new MailRemoteException('Gmail API nicht erreichbar: '.$exception->getMessage(), 'gmail', 503, null);
        }
    }

    /**
     * Wiederholbare Parameter (labelIds, historyTypes, metadataHeaders) ohne Index-Klammern, wie die API sie erwartet.
     *
     * @param  array<string, mixed>  $query
     */
    private function buildQuery(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode((string) $key).'='.rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return implode('&', $pairs);
    }

    private function excerpt(Response $response): ?string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return mb_substr($message, 0, 200);
        }

        $body = $response->body();

        return $body !== '' ? mb_substr(strip_tags($body), 0, 200) : null;
    }
}
