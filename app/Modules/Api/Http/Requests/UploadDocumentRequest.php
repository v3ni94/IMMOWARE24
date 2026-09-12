<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests;

use App\Core\Support\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /documents: Multipart mit file (Pflicht), filename (Pflicht), connection_id, case_id, source_document_id, note.
 * case_id und source_document_id müssen existieren und zum Mandanten des API-Keys gehören (sonst 422).
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
        $organizationId = $this->container->make(OrganizationContext::class)->get();
        $inOrganization = static fn (string $table) => Rule::exists($table, 'id')
            ->where('organization_id', $organizationId ?? -1)
            ->whereNull('deleted_at');

        return [
            'file' => ['required', 'file', 'max:'.$maxKb],
            'filename' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/'],
            'connection_id' => ['nullable', 'integer', 'min:1', Rule::exists('immoware_connections', 'id')->where('organization_id', $organizationId ?? -1)],
            'case_id' => ['nullable', 'integer', 'min:1', $inOrganization('cases')],
            'source_document_id' => ['nullable', 'integer', 'min:1', $inOrganization('documents')],
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
            'case_id.exists' => 'Der Vorgang existiert nicht oder gehört nicht zu diesem Mandanten.',
            'source_document_id.exists' => 'Das Quelldokument existiert nicht oder gehört nicht zu diesem Mandanten.',
            'connection_id.exists' => 'Die Connection existiert nicht oder gehört nicht zu diesem Mandanten.',
        ];
    }
}
