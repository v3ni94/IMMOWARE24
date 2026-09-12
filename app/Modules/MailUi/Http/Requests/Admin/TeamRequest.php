<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class TeamRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'lead_user_id' => ['nullable', 'integer'],
            'escalation_user_id' => ['nullable', 'integer'],
        ];
    }
}
