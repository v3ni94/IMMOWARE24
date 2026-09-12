<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use App\Modules\MailUi\Support\CaseTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sammelaktion der Team-Inbox. Erlaubt nur assign, category, task. Freigabe und Versand sind keine Sammelaktionen.
 */
final class BulkActionRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(['assign', 'category', 'task'])],
            'case_ids' => ['required', 'array', 'min:1', 'max:200'],
            'case_ids.*' => ['integer', 'min:1'],
            'assignee_user_id' => ['nullable', 'integer'],
            'next_step' => ['nullable', 'string', 'max:500'],
            'due_at' => ['nullable', 'string', 'max:20'],
            'case_type' => ['required_if:action,category', 'nullable', 'string', Rule::in(array_keys(CaseTypes::all()))],
            'task_title' => ['required_if:action,task', 'nullable', 'string', 'max:300'],
        ];
    }
}
