<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use App\Modules\Cases\Enums\CaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(array_map(static fn (CaseStatus $s): string => $s->value, CaseStatus::cases()))],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
