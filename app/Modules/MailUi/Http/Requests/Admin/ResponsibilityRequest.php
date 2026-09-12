<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResponsibilityRequest extends FormRequest
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
            'property_id' => ['required', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'role' => ['required', 'string', Rule::in(['primary', 'substitute'])],
        ];
    }
}
