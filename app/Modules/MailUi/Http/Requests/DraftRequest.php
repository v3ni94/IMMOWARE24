<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DraftRequest extends FormRequest
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
            'alias_id' => ['nullable', 'integer'],
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:998'],
            'body_text' => ['required', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function addresses(string $field): array
    {
        $raw = (string) $this->validated($field, '');
        $parts = preg_split('/[;,\s]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $address): bool => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false));
    }
}
