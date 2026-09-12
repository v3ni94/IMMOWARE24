<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AliasRequest extends FormRequest
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
            'send_as_email' => ['required', 'string', 'email', 'max:254'],
            'display_name' => ['nullable', 'string', 'max:200'],
            'reply_to' => ['nullable', 'string', 'email', 'max:254'],
            'legal_entity_code' => ['required', 'string', Rule::in(array_keys((array) config('hub.mail.legal_entities', [])))],
            'signature_key' => ['nullable', 'string', 'max:60'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
