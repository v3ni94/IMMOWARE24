<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Jobs;

use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookOutbox;
use App\Modules\Webhooks\Services\WebhookSigner;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Stellt eine Webhook-Zustellung zu: HMAC-SHA256-Signatur, Timeout 10 s, Retry 30 s, 2 min, 10 min, 30 min,
 * danach Status dead (DLQ). Jeder Versuch wird mit Status, Dauer und Antwortcode protokolliert.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tries = (int) config('hub.webhooks.max_attempts', 5);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map('intval', (array) config('hub.webhooks.backoff_seconds', [30, 120, 600, 1800]));
    }

    public function handle(WebhookSigner $signer): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->getAttribute('status'), [WebhookDelivery::STATUS_DELIVERED, WebhookDelivery::STATUS_DEAD, WebhookDelivery::STATUS_SKIPPED], true)) {
            return;
        }

        /** @var WebhookEndpoint|null $endpoint */
        $endpoint = WebhookEndpoint::query()->allOrganizations()->find($delivery->getAttribute('endpoint_id'));
        /** @var WebhookOutbox|null $outbox */
        $outbox = WebhookOutbox::query()->find($delivery->getAttribute('outbox_id'));

        if ($endpoint === null || $outbox === null || ! (bool) $endpoint->getAttribute('active')) {
            $delivery->forceFill(['status' => WebhookDelivery::STATUS_SKIPPED, 'last_error' => 'Endpunkt inaktiv oder entfernt.'])->save();

            return;
        }

        $body = json_encode($outbox->getAttribute('payload_json'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = $signer->signature((string) $endpoint->getAttribute('secret'), $timestamp, $body, $endpoint->getAttribute('previous_secret'));
        $signatureConfig = (array) config('hub.webhooks.signature', []);

        $attempt = (int) $delivery->getAttribute('attempts') + 1;
        $maxAttempts = (int) config('hub.webhooks.max_attempts', 5);
        $started = microtime(true);
        $responseCode = null;
        $excerpt = null;
        $error = null;

        try {
            $response = Http::timeout((int) config('hub.webhooks.timeout_seconds', 10))
                ->withHeaders([
                    (string) ($signatureConfig['header'] ?? 'X-Hub-Signature') => $signature,
                    (string) ($signatureConfig['event_header'] ?? 'X-Hub-Event') => (string) $outbox->getAttribute('event_type'),
                    (string) ($signatureConfig['delivery_header'] ?? 'X-Hub-Delivery') => (string) $delivery->getAttribute('delivery_uuid'),
                    'X-Hub-Event-Id' => (string) $outbox->getAttribute('event_id'),
                    'X-Hub-Timestamp' => (string) $timestamp,
                    'User-Agent' => 'ImmowareHub-Webhooks/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post((string) $endpoint->getAttribute('url'));

            $responseCode = $response->status();
            $excerpt = $this->excerpt($response);

            if (! $response->successful()) {
                $error = 'HTTP '.$responseCode;
            }
        } catch (Throwable $e) {
            $error = class_basename($e);
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        $now = CarbonImmutable::now();

        $delivery->forceFill([
            'attempts' => $attempt,
            'signature' => substr(hash('sha256', $signature), 0, 64),
            'last_response_code' => $responseCode,
            'duration_ms' => $duration,
            'response_excerpt' => $excerpt,
            'last_attempt_at' => $now,
            'last_error' => $error,
        ]);

        if ($error === null) {
            $delivery->forceFill(['status' => WebhookDelivery::STATUS_DELIVERED, 'delivered_at' => $now, 'next_attempt_at' => null])->save();

            return;
        }

        if ($attempt >= $maxAttempts) {
            $delivery->forceFill(['status' => WebhookDelivery::STATUS_DEAD, 'dead_at' => $now, 'next_attempt_at' => null])->save();
            Log::warning('Webhook-Zustellung nach letztem Versuch in DLQ', ['delivery_id' => $delivery->getKey(), 'attempts' => $attempt, 'response_code' => $responseCode]);

            return;
        }

        $backoff = $this->backoff();
        $wait = $backoff[min($attempt - 1, count($backoff) - 1)] ?? 30;

        $delivery->forceFill(['status' => WebhookDelivery::STATUS_FAILED, 'next_attempt_at' => $now->addSeconds($wait)])->save();

        throw new RuntimeException(sprintf('Webhook-Zustellung %d fehlgeschlagen (%s), Versuch %d von %d.', $this->deliveryId, $error, $attempt, $maxAttempts));
    }

    public function failed(?Throwable $exception): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->getAttribute('status') === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_DEAD,
            'dead_at' => CarbonImmutable::now(),
            'next_attempt_at' => null,
            'last_error' => $exception !== null ? class_basename($exception) : ($delivery->getAttribute('last_error') ?? 'failed'),
        ])->save();
    }

    private function excerpt(Response $response): string
    {
        $limit = (int) config('hub.webhooks.response_excerpt_bytes', 512);

        return mb_substr((string) $response->body(), 0, $limit);
    }
}
