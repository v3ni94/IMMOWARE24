<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Imports\Enums\HubExportFormat;
use App\Modules\Imports\Services\HubExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreHubExportRequest extends FormRequest
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
            'entity' => ['required', 'string', Rule::in(array_keys(HubExportService::ENTITIES))],
            'format' => ['required', 'string', Rule::in(array_map(static fn (HubExportFormat $f): string => $f->value, HubExportFormat::cases()))],
            'filter_column' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]*$/'],
            'filter_value' => ['nullable', 'string', 'max:255'],
            'include_deleted' => ['sometimes', 'boolean'],
        ];
    }
}
