<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use App\Modules\MailUi\Support\CaseTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetCategoryRequest extends FormRequest
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
        return ['case_type' => ['required', 'string', Rule::in(array_keys(CaseTypes::all()))]];
    }
}
