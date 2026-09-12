<?php

declare(strict_types=1);

namespace App\Modules\Security\Models;

use App\Core\Enums\AuditSource;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only Auditlog mit Hash-Kette. Update und Delete sind auf Modellebene gesperrt;
 * in Produktion zusätzlich durch Datenbankrechte (nur INSERT und SELECT).
 */
class AuditLog extends Model
{
    public const null UPDATED_AT = null;

    public const string GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    protected $table = 'audit_logs';

    /** @var array<int, string> */
    protected $guarded = ['id', 'prev_hash', 'row_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'source' => AuditSource::class,
            'before_json' => 'array',
            'after_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            if ($log->getAttribute('occurred_at') === null) {
                $log->setAttribute('occurred_at', now()->toImmutable());
            }

            $previous = self::query()->orderByDesc('id')->value('row_hash');
            $log->setAttribute('prev_hash', is_string($previous) ? $previous : self::GENESIS_HASH);
            $log->setAttribute('row_hash', $log->computeRowHash());
        });

        static::updating(function (): never {
            throw new LogicException('audit_logs ist append-only: Update ist nicht erlaubt.');
        });

        static::deleting(function (): never {
            throw new LogicException('audit_logs ist append-only: Delete ist nicht erlaubt.');
        });
    }

    /**
     * Kanonische Darstellung aller fachlichen Felder, unabhängig von Treiber- und Cast-Formaten.
     */
    public function computeRowHash(): string
    {
        $occurredAt = $this->getAttribute('occurred_at');
        $source = $this->getAttribute('source');

        $canonical = [
            'occurred_at' => $occurredAt instanceof \DateTimeInterface ? $occurredAt->format('Y-m-d\TH:i:s.uP') : (string) $occurredAt,
            'organization_id' => $this->intOrNull('organization_id'),
            'actor_type' => (string) $this->getAttribute('actor_type'),
            'actor_id' => $this->intOrNull('actor_id'),
            'source' => $source instanceof AuditSource ? $source->value : (string) $source,
            'action' => (string) $this->getAttribute('action'),
            'entity_type' => $this->getAttribute('entity_type'),
            'entity_id' => $this->intOrNull('entity_id'),
            'connection_id' => $this->intOrNull('connection_id'),
            'before_json' => $this->getAttribute('before_json'),
            'after_json' => $this->getAttribute('after_json'),
            'correlation_id' => $this->getAttribute('correlation_id'),
            'ip_address_hash' => $this->getAttribute('ip_address_hash'),
            'prev_hash' => (string) $this->getAttribute('prev_hash'),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function intOrNull(string $attribute): ?int
    {
        $value = $this->getAttribute($attribute);

        return $value === null ? null : (int) $value;
    }

    /**
     * Prüft die Kette gegen den Vorgänger.
     */
    public function verifyChain(?self $previous): bool
    {
        $expectedPrev = $previous?->getAttribute('row_hash') ?? self::GENESIS_HASH;

        return $this->getAttribute('prev_hash') === $expectedPrev && $this->getAttribute('row_hash') === $this->computeRowHash();
    }
}
