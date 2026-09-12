<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Http\Controllers\ConnectionsController;
use App\Modules\Admin\Rules\AllowedConnectionHost;
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
            'purpose' => ['required', 'string', Rule::in(['read', 'write'])],
            'base_url' => ['nullable', 'string', 'max:2048', 'url:https', 'required_if:connector_type,webdav_documents,webdav_inbox,carddav_contacts,caldav_calendar', new AllowedConnectionHost],
            'technical_user_id' => ['nullable', 'integer'],
            'username' => ['nullable', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'max:1024'],
            'poll_interval_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'rate_limit_rps' => ['required', 'numeric', 'min:0.25', 'max:10'],
            'allowed_write_prefix' => ['nullable', 'string', 'max:512'],
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
        ];
    }
}
