<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmImportFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:120'],
            'key_schema' => ['required', 'array', 'min:1'],
            'key_schema.*' => ['string', 'max:120'],
            'confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mapping.required' => 'Bitte mindestens eine Zielspalte zuordnen.',
            'key_schema.required' => 'Bitte mindestens ein Schlüsselfeld wählen.',
            'key_schema.min' => 'Bitte mindestens ein Schlüsselfeld wählen.',
            'confirmation.required' => 'Bitte das Bestätigungswort eingeben.',
        ];
    }
}
