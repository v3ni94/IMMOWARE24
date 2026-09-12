<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Core\Support\OrganizationContext;
use App\Core\Support\SecretMasker;
use App\Modules\Security\DTO\AuditActor;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Schreibt append-only Auditeinträge mit Hash-Kette. Maskiert Geheimnisse, hasht die IP-Adresse
 * und ermittelt Akteur, Quelle und Correlation-ID aus dem aktuellen Request bzw. Kontext.
 */
final class AuditLogger implements AuditLoggerInterface
{
    /** Cache-Lock, der das Anhängen an die Hash-Kette über alle Prozesse serialisiert. */
    public const string CHAIN_LOCK = 'audit:chain';

    private ?AuditActor $actorOverride = null;

    public function __construct(
        private readonly SecretMasker $masker,
        private readonly PepperedHasher $hasher,
        private readonly CorrelationId $correlationId,
        private readonly OrganizationContext $organizationContext,
    ) {}

    /**
     * Setzt den Akteur explizit (z. B. Jobs oder Commands ohne Request).
     */
    public function asActor(?AuditActor $actor): self
    {
        $this->actorOverride = $actor;

        return $this;
    }

    public function log(
        string $action,
        ?Model $entity,
        array $before,
        array $after,
        string $source,
        ?string $correlationId = null,
    ): void {
        $this->record(
            action: $action,
            entity: $entity,
            before: $before,
            after: $after,
            source: AuditSource::tryFrom($source) ?? AuditSource::System,
            correlationId: $correlationId,
        );
    }

    /**
     * Erweiterter Eintrag mit optionalem Akteur, Request und Connection.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        string $action,
        ?Model $entity = null,
        array $before = [],
        array $after = [],
        AuditSource $source = AuditSource::User,
        ?string $correlationId = null,
        ?AuditActor $actor = null,
        ?Request $request = null,
        ?int $connectionId = null,
    ): AuditLog {
        $request ??= $this->currentRequest();
        $actor ??= $this->actorOverride ?? $this->resolveActor($request);

        $attributes = [
            'occurred_at' => now()->toImmutable(),
            'organization_id' => $actor->organizationId ?? $this->organizationContext->get() ?? $this->entityOrganization($entity),
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'source' => $source,
            'action' => $action,
            'entity_type' => $entity !== null ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey() !== null ? (int) $entity->getKey() : null,
            'connection_id' => $connectionId,
            'before_json' => $this->masker->maskArray($before),
            'after_json' => $this->masker->maskArray($after),
            'correlation_id' => $correlationId ?? $this->correlationId->current(),
            'ip_address_hash' => $this->hasher->hashIp($request?->ip()),
        ];

        $userAgent = $request?->userAgent();

        if (is_string($userAgent) && $userAgent !== '') {
            $attributes['after_json']['_user_agent'] = mb_substr($userAgent, 0, 255);
        }

        // Kette serialisieren: prozessübergreifender Sequenz-Lock plus lockForUpdate() auf die letzte Zeile
        // (AuditLog::booted). prev_hash wird beim creating aus der letzten Zeile gelesen; der Unique-Index
        // auf prev_hash fängt eine trotzdem entstandene Verzweigung ab.
        return Cache::lock(self::CHAIN_LOCK, 10)->block(5, static fn (): AuditLog => DB::transaction(
            static fn (): AuditLog => AuditLog::query()->create($attributes),
        ));
    }

    private function currentRequest(): ?Request
    {
        if (! app()->bound('request') || app()->runningInConsole()) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private function resolveActor(?Request $request): AuditActor
    {
        if ($request === null) {
            return AuditActor::system();
        }

        $apiKey = $request->attributes->get('api_key');

        if ($apiKey instanceof ApiKey) {
            return AuditActor::apiKey((int) $apiKey->getKey(), $this->intOrNull($apiKey->getAttribute('organization_id')));
        }

        $user = $request->user();

        if ($user instanceof User) {
            return AuditActor::user((int) $user->getKey(), $this->intOrNull($user->getAttribute('organization_id')));
        }

        return AuditActor::system();
    }

    private function entityOrganization(?Model $entity): ?int
    {
        if ($entity === null) {
            return null;
        }

        return $this->intOrNull($entity->getAttribute('organization_id'));
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
