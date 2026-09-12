<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfirmCandidateRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(['contact', 'property', 'unit', 'contract'])],
            'local_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
