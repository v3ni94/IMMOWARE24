<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConflictResolveRequest extends FormRequest
{
    /**
     * Rechteprüfung vor der Validierung (403 statt Validierungsfehler für unberechtigte Rollen).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('conflicts.resolve') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(['local', 'remote', 'manual'])],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['resolution' => 'Auflösung', 'note' => 'Begründung', 'confirmation' => 'Bestätigung'];
    }
}
