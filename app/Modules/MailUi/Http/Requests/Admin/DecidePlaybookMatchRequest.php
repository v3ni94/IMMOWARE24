<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecidePlaybookMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Rechteprüfung erfolgt im Controller (requireAdmin), wie bei den übrigen Administrationsformularen.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['accepted', 'adjusted', 'rejected'])],
            'deviations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
