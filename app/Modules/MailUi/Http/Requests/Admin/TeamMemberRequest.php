<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use App\Modules\Mail\Services\MailAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TeamMemberRequest extends FormRequest
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
            'team_role' => ['required', 'string', Rule::in(MailAccess::TEAM_ROLES)],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date', 'after_or_equal:active_from'],
        ];
    }
}
