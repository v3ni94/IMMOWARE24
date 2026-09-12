<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InternalTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:300'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'assignee_user_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'string', 'max:20'],
        ];
    }
}
