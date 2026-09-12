<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class MailboxPermissionRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'min:1'],
            'can_read' => ['nullable', 'boolean'],
            'can_draft' => ['nullable', 'boolean'],
            'can_send' => ['nullable', 'boolean'],
            'can_assign' => ['nullable', 'boolean'],
            'can_view_bank_data' => ['nullable', 'boolean'],
        ];
    }
}
