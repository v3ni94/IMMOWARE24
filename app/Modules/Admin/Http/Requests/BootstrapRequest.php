<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Sync\Enums\SyncEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bootstrap-Assistent: eine Stufe je Aufruf (1, 10, 100, 1000 synchron, "alle" als Full-Sync-Job).
 */
final class BootstrapRequest extends FormRequest
{
    /** @var array<int, string> */
    public const array STAGES = ['1', '10', '100', '1000', 'alle'];

    /**
     * Rechteprüfung vor der Validierung (403 statt Validierungsfehler für unberechtigte Rollen).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('sync.run') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'connection_id' => ['required', 'integer', 'min:1'],
            'entity_type' => ['required', 'string', Rule::in(SyncEntity::values())],
            'stage' => ['required', 'string', Rule::in(self::STAGES)],
            'confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['connection_id' => 'Connection', 'entity_type' => 'Entität', 'stage' => 'Stufe', 'confirmation' => 'Bestätigung'];
    }
}
