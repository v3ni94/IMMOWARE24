<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideLearningSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('learning.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['accepted', 'rejected'])],
        ];
    }
}
