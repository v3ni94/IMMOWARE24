<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\MappingVersionRequest;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Mapping\VersionedFieldMapper;
use App\Modules\Sync\Models\FieldMapping;
use App\Modules\Sync\Services\FieldMappingService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Feldmappings je Entität und Version (Immoware-Feld, Hub-Feld, Transform). Versionen vergleichen,
 * neue Version prüfen und mit Bestätigung aktivieren (FieldMappingService::publish, auditiert).
 * Lesen für alle Rollen, Anlegen und Aktivieren erfordert connections.manage (Formatversionen, 08-security.md Abschnitt 4).
 */
final class MappingController extends AdminController
{
    private const string PERMISSION = 'connections.manage';

    /** @var array<int, string> Zulässige Transformationen (VersionedFieldMapper::transform) */
    public const array TRANSFORMS = ['trim', 'lower', 'upper', 'int', 'bool', 'digits', 'phone_list', 'list', 'csv_list', 'date', 'datetime', 'mailto', 'ical_unescape', 'vcard_kind', 'dav_path', 'dav_is_collection'];

    public function __construct(private readonly FieldMappingService $mappings) {}

    public function index(Request $request): View
    {
        $query = FieldMapping::query()->orderBy('entity_type')->orderBy('source_format')->orderByDesc('version');

        $filters = [
            'entity_type' => (string) $request->query('entity_type', ''),
            'status' => (string) $request->query('status', ''),
        ];

        if (in_array($filters['entity_type'], SyncEntity::values(), true)) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (in_array($filters['status'], [FieldMappingService::STATUS_ACTIVE, FieldMappingService::STATUS_RETIRED, FieldMappingService::STATUS_DRAFT], true)) {
            $query->where('status', $filters['status']);
        }

        $active = [];

        foreach (SyncEntity::cases() as $entity) {
            $active[$entity->value] = $this->mappings->active($entity->value, $entity->sourceFormat());
        }

        return view('admin::mapping.index', [
            'mappings' => $query->paginate($this->perPage())->withQueryString(),
            'filters' => $filters,
            'active' => $active,
            'canManage' => $this->allows(self::PERMISSION),
        ]);
    }

    public function show(int $id): View
    {
        /** @var FieldMapping $mapping */
        $mapping = FieldMapping::query()->with(['createdBy', 'activatedBy', 'previousVersion'])->findOrFail($id);

        $versions = FieldMapping::query()
            ->where('source_system', $mapping->getAttribute('source_system'))
            ->where('entity_type', $mapping->getAttribute('entity_type'))
            ->where('source_format', $mapping->getAttribute('source_format'))
            ->orderByDesc('version')
            ->limit(100)
            ->get();

        return view('admin::mapping.show', [
            'mapping' => $mapping,
            'rules' => (array) $mapping->getAttribute('mapping'),
            'versions' => $versions,
            'canManage' => $this->allows(self::PERMISSION),
        ]);
    }

    public function compare(Request $request): View
    {
        $a = FieldMapping::query()->findOrFail((int) $request->query('a', '0'));
        $b = FieldMapping::query()->findOrFail((int) $request->query('b', '0'));

        return view('admin::mapping.compare', [
            'a' => $a,
            'b' => $b,
            'diff' => $this->diff((array) $a->getAttribute('mapping'), (array) $b->getAttribute('mapping')),
        ]);
    }

    public function create(Request $request): View
    {
        $this->requirePermission(self::PERMISSION);

        $entity = SyncEntity::tryFrom((string) $request->query('entity_type', '')) ?? SyncEntity::Contact;
        $sourceFormat = (string) $request->query('source_format', $entity->sourceFormat());
        $current = $this->mappings->active($entity->value, $sourceFormat);

        return view('admin::mapping.create', [
            'entity' => $entity,
            'sourceFormat' => $sourceFormat,
            'current' => $current,
            'rulesText' => old('rules_text', $current !== null ? MappingVersionRequest::rulesToText((array) $current->getAttribute('mapping')) : ''),
            'transforms' => self::TRANSFORMS,
        ]);
    }

    /**
     * Prüft die Regeln und zeigt den Vergleich mit der aktiven Version; die Aktivierung erfolgt erst
     * über das bestätigte Formular in der Prüfansicht.
     */
    public function review(MappingVersionRequest $request): View|RedirectResponse
    {
        $this->requirePermission(self::PERMISSION);

        $data = $request->validated();
        $rules = $this->parseAndValidate((string) $data['entity_type'], (string) $data['rules_text']);
        $current = $this->mappings->active((string) $data['entity_type'], (string) $data['source_format']);

        return view('admin::mapping.review', [
            'entity' => SyncEntity::from((string) $data['entity_type']),
            'sourceFormat' => (string) $data['source_format'],
            'notes' => (string) ($data['notes'] ?? ''),
            'rules' => $rules,
            'rulesText' => (string) $data['rules_text'],
            'current' => $current,
            'diff' => $this->diff($current !== null ? (array) $current->getAttribute('mapping') : [], $rules),
        ]);
    }

    public function store(MappingVersionRequest $request): RedirectResponse
    {
        $this->requirePermission(self::PERMISSION);
        $this->requireConfirmation($request);

        $data = $request->validated();
        $rules = $this->parseAndValidate((string) $data['entity_type'], (string) $data['rules_text']);
        $user = $this->currentUser($request);
        $before = $this->mappings->active((string) $data['entity_type'], (string) $data['source_format']);

        $mapping = $this->mappings->publish(
            (string) $data['entity_type'],
            (string) $data['source_format'],
            $rules,
            $user,
            isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
        );

        if ($before !== null && (int) $before->getKey() === (int) $mapping->getKey()) {
            return $this->redirectWithWarning('admin.mapping.show', 'Keine Änderung gegenüber der aktiven Version v'.$mapping->getAttribute('version').', keine neue Version angelegt.', ['id' => $mapping->getKey()]);
        }

        $this->audit('mapping.activated', $mapping, [
            'version' => $before?->getAttribute('version'),
            'rules_count' => $before !== null ? count((array) $before->getAttribute('mapping')) : 0,
        ], [
            'version' => $mapping->getAttribute('version'),
            'rules_count' => count($rules),
            'entity_type' => $data['entity_type'],
            'source_format' => $data['source_format'],
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->redirectWithStatus('admin.mapping.show', 'Mapping-Version v'.$mapping->getAttribute('version').' angelegt und aktiviert. Vorherige Version ist retired.', ['id' => $mapping->getKey()]);
    }

    /**
     * @return array<int, array{source_field: string, target_field: string, transform: string|null}>
     */
    private function parseAndValidate(string $entityType, string $text): array
    {
        try {
            $rules = MappingVersionRequest::parseRules($text);
            new VersionedFieldMapper($entityType, 0, $rules, []);

            foreach ($rules as $index => $rule) {
                if ($rule['transform'] !== null && ! in_array($rule['transform'], self::TRANSFORMS, true)) {
                    throw new InvalidArgumentException(sprintf('Regel %d: unbekannte Transformation "%s".', $index + 1, $rule['transform']));
                }
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rules_text' => $exception->getMessage()]);
        }

        return $rules;
    }

    /**
     * Regelvergleich nach target_field: unverändert, geändert, neu, entfernt.
     *
     * @param  array<int, mixed>  $old
     * @param  array<int, mixed>  $new
     * @return array<int, array{target_field: string, state: string, old: array<string, mixed>|null, new: array<string, mixed>|null}>
     */
    private function diff(array $old, array $new): array
    {
        $byTarget = static function (array $rules): array {
            $map = [];

            foreach ($rules as $rule) {
                $rule = (array) $rule;
                $map[(string) ($rule['target_field'] ?? '')] = [
                    'source_field' => (string) ($rule['source_field'] ?? ''),
                    'target_field' => (string) ($rule['target_field'] ?? ''),
                    'transform' => ($rule['transform'] ?? null) ?: null,
                ];
            }

            return $map;
        };

        $a = $byTarget($old);
        $b = $byTarget($new);
        $result = [];

        foreach ($b as $target => $rule) {
            $previous = $a[$target] ?? null;
            $result[] = [
                'target_field' => $target,
                'state' => $previous === null ? 'neu' : ($previous === $rule ? 'unverändert' : 'geändert'),
                'old' => $previous,
                'new' => $rule,
            ];
        }

        foreach ($a as $target => $rule) {
            if (! array_key_exists($target, $b)) {
                $result[] = ['target_field' => $target, 'state' => 'entfernt', 'old' => $rule, 'new' => null];
            }
        }

        return $result;
    }

    private function allows(string $permission): bool
    {
        /** @var Gate $gate */
        $gate = app(Gate::class);

        return $gate->allows($permission);
    }
}
