<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Learning\Enums\LearningKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartLearningRunRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::in(array_map(static fn (LearningKind $k): string => $k->value, LearningKind::cases()))],
            'connection_id' => ['nullable', 'integer', 'min:1'],
            'with_ai' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['kind' => 'Art', 'connection_id' => 'Verbindung'];
    }
}
