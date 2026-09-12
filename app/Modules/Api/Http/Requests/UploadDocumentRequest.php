<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /documents: Multipart mit file (Pflicht), filename (Pflicht), connection_id, case_id, note.
 */
final class UploadDocumentRequest extends FormRequest
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
        $maxKb = (int) ceil(((int) config('hub.core.write.max_upload_bytes', 26214400)) / 1024);

        return [
            'file' => ['required', 'file', 'max:'.$maxKb],
            'filename' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/'],
            'connection_id' => ['nullable', 'integer', 'min:1'],
            'case_id' => ['nullable', 'integer', 'min:1'],
            'source_document_id' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'filename.regex' => 'Der Dateiname darf nur ASCII-Zeichen, Ziffern, Punkt, Unterstrich, Leerzeichen und Bindestrich enthalten und nicht mit einem Punkt beginnen.',
        ];
    }
}
