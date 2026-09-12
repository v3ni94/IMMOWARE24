<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DlqIgnoreRequest extends FormRequest
{
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
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => 'Begründung', 'confirmation' => 'Bestätigung'];
    }
}
