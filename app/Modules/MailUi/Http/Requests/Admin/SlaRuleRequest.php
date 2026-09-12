<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use App\Modules\Cases\Enums\Priority;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\MailUi\Support\CaseTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SlaRuleRequest extends FormRequest
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
            'team_id' => ['nullable', 'integer'],
            'priority' => ['required', 'string', Rule::in(array_map(static fn (Priority $p): string => $p->value, Priority::cases()))],
            'case_type' => ['nullable', 'string', Rule::in(array_keys(CaseTypes::all()))],
            'clock_type' => ['required', 'string', Rule::in(['acknowledge', 'first_response', 'resolve', 'task_due'])],
            'target_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'warn_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            'escalate_after_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'escalate_to_role' => ['nullable', 'string', Rule::in(MailAccess::TEAM_ROLES)],
        ];
    }
}
