<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreApiKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in((array) config('hub.security.api_keys.scopes', []))],
            'expires_at' => ['required', 'date_format:Y-m-d', 'after:today'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scopes.required' => 'Bitte mindestens einen Scope wählen.',
            'expires_at.after' => 'Das Ablaufdatum muss in der Zukunft liegen.',
        ];
    }
}
