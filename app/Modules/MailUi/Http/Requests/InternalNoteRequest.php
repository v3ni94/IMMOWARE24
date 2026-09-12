<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InternalNoteRequest extends FormRequest
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
        return ['note' => ['required', 'string', 'max:2000']];
    }
}
