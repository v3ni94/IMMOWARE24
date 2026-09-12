<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class HolidayRequest extends FormRequest
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
            'holiday_date' => ['required', 'string', 'max:10'],
            'label' => ['required', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:8'],
        ];
    }
}
