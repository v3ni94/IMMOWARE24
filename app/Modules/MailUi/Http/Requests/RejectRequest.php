<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
