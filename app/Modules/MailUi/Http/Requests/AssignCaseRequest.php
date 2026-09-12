<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignCaseRequest extends FormRequest
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
            'assignee_user_id' => ['nullable', 'integer'],
            'next_step' => ['nullable', 'string', 'max:500'],
            'due_at' => ['nullable', 'string', 'max:20'],
        ];
    }
}
