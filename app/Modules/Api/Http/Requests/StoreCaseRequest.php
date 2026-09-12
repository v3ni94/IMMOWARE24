<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCaseRequest extends FormRequest
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
            'title' => ['required', 'string', 'min:3', 'max:300'],
            'status' => ['sometimes', 'string', Rule::in((array) config('hub.api.case_statuses', ['open']))],
            'property_id' => ['nullable', 'integer', 'min:1'],
            'unit_id' => ['nullable', 'integer', 'min:1'],
            'contact_id' => ['nullable', 'integer', 'min:1'],
            'immoware_ticket_reference' => ['nullable', 'string', 'max:64'],
        ];
    }
}
