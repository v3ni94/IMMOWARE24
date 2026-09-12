<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Imports\Enums\ExportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreExportScheduleRequest extends FormRequest
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
        $update = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'connection_id' => [$update ? 'sometimes' : 'required', 'integer'],
            'export_type' => [$update ? 'sometimes' : 'required', 'string', Rule::in(array_map(static fn (ExportType $t): string => $t->value, ExportType::cases()))],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'interval_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
