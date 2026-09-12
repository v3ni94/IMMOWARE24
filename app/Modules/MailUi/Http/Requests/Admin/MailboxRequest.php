<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MailboxRequest extends FormRequest
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
        $entities = array_keys((array) config('hub.mail.legal_entities', []));

        return [
            'label' => ['required', 'string', 'min:2', 'max:120'],
            'email_address' => [$this->isMethod('POST') && $this->route('mailbox') === null ? 'required' : 'nullable', 'string', 'email', 'max:254'],
            'team_id' => ['nullable', 'integer'],
            'legal_entity_code' => ['required', 'string', Rule::in($entities)],
            'import_enabled' => ['nullable', 'boolean'],
        ];
    }
}
