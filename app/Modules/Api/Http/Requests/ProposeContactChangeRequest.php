<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /contacts/{id}: Body {"changes": {"feld": neuer Wert}, "reason": "..."}.
 * Erzeugt ausschließlich Änderungsvorschläge, nie einen Writeback.
 */
final class ProposeContactChangeRequest extends FormRequest
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
            'changes' => ['required', 'array', 'min:1', 'max:20'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $allowed = (array) config('hub.api.contact_proposal_fields', []);
            $changes = $this->input('changes');

            if (! is_array($changes)) {
                return;
            }

            foreach (array_keys($changes) as $field) {
                if (! in_array((string) $field, $allowed, true)) {
                    $v->errors()->add('changes.'.$field, sprintf('Das Feld "%s" darf nicht vorgeschlagen werden. Erlaubt: %s.', $field, implode(', ', $allowed)));
                }
            }
        });
    }
}
