<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Http\Controllers\ConnectionsController;
use App\Modules\Admin\Rules\AllowedConnectionHost;
use App\Modules\Admin\Rules\AllowedWritePrefix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Anlage und Bearbeitung einer ImmowareConnection. Das Freigabe-Passwort ist ein reines Schreibfeld:
 * leer bedeutet beim Bearbeiten "unverändert", es wird nie zurückgegeben.
 */
final class ConnectionRequest extends FormRequest
{
    /**
     * Rechteprüfung vor der Validierung (403 statt Validierungsfehler für unberechtigte Rollen).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('connections.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'connector_type' => ['required', 'string', Rule::in(array_keys(ConnectionsController::CONNECTOR_TYPES))],
            // Der Schreibpfad ist ausschließlich WebDAV (05-write-capabilities.md 2.2 Nr. 1).
            'purpose' => ['required', 'string', Rule::in(['read', 'write']), Rule::prohibitedIf(fn (): bool => $this->input('purpose') === 'write' && ! in_array((string) $this->input('connector_type'), ['webdav_documents', 'webdav_inbox'], true))],
            'base_url' => ['nullable', 'string', 'max:2048', 'url:https', 'required_if:connector_type,webdav_documents,webdav_inbox,carddav_contacts,caldav_calendar', new AllowedConnectionHost],
            'technical_user_id' => ['nullable', 'integer'],
            'username' => ['nullable', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'max:1024'],
            'poll_interval_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'rate_limit_rps' => ['required', 'numeric', 'min:0.25', 'max:10'],
            'allowed_write_prefix' => ['nullable', 'string', 'max:512', 'required_if:purpose,write', new AllowedWritePrefix],
            // 02-data-model.md: Eine Schreib-Connection verweist auf die Lese-Connection, in deren Spiegel hochgeladene
            // Dateien erscheinen (kein Rückfall auf die Schreib-Connection selbst). Pflicht bei purpose write.
            'paired_read_connection_id' => [
                'nullable',
                'integer',
                'required_if:purpose,write',
                Rule::prohibitedIf(fn (): bool => (string) $this->input('purpose') !== 'write'),
                Rule::notIn(array_filter([$this->route('id')])),
                Rule::exists('immoware_connections', 'id')
                    ->where('organization_id', $this->user()?->getAttribute('organization_id'))
                    ->where('purpose', 'read')
                    ->where('connector_type', 'webdav_documents'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Bezeichnung',
            'connector_type' => 'Connector-Typ',
            'purpose' => 'Zweck',
            'base_url' => 'Freigabe-URL',
            'technical_user_id' => 'Technischer Nutzer',
            'username' => 'Benutzername',
            'password' => 'Freigabe-Passwort',
            'poll_interval_seconds' => 'Abrufintervall',
            'rate_limit_rps' => 'Rate-Limit',
            'allowed_write_prefix' => 'Erlaubter Schreibpfad',
            'paired_read_connection_id' => 'Zugeordnete Lese-Connection',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_url.required_if' => 'Für DAV-Connections ist die Freigabe-URL erforderlich.',
            'base_url.url' => 'Die Freigabe-URL muss mit https:// beginnen.',
            'purpose.prohibited' => 'Zweck write ist nur für WebDAV-Connections zulässig (create-only PUT in den Posteingang).',
            'allowed_write_prefix.required_if' => 'Für eine Schreib-Connection ist der erlaubte Schreibpfad erforderlich.',
            'paired_read_connection_id.required_if' => 'Für eine Schreib-Connection ist die zugeordnete Lese-Connection (WebDAV Dokumente, Zweck Lesen, gleicher Mandant) erforderlich.',
            'paired_read_connection_id.exists' => 'Die zugeordnete Lese-Connection muss eine WebDAV-Dokumente-Connection mit Zweck Lesen des eigenen Mandanten sein.',
            'paired_read_connection_id.prohibited' => 'Eine Lese-Connection wird nur für Zweck Schreiben zugeordnet.',
            'paired_read_connection_id.not_in' => 'Eine Connection kann nicht ihre eigene Lese-Connection sein.',
        ];
    }
}
