<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Models\MailboxPermission;
use App\Modules\Mail\Models\Team;
use App\Modules\MailUi\Http\Requests\Admin\AliasRequest;
use App\Modules\MailUi\Http\Requests\Admin\MailboxPermissionRequest;
use App\Modules\MailUi\Http\Requests\Admin\MailboxRequest;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Postfächer, Aliasse und Postfachrechte. Ein Alias ist kein Postfach: eine Adresse, die als Alias eines Postfachs
 * geführt wird, kann nicht als Postfach angelegt werden und umgekehrt. Gesellschaft (legal_entity_code) ist Pflicht.
 * OAuth-Verbindung erfolgt über die Integrationsseite (Modul Gmail), hier nur Stammdaten.
 */
final class MailboxesController extends AdminBaseController
{
    public function index(Request $request): View
    {
        $user = $this->requireAdmin($request);

        return view('mail::admin.mailboxes.index', [
            'title' => 'Postfächer, Aliasse und Rechte',
            'mailboxes' => Mailbox::query()->with(['team', 'aliases', 'permissions.user'])->orderBy('label')->get(),
            'teams' => Team::query()->orderBy('name')->get(),
            'users' => $this->organizationUsers($user),
            'legalEntities' => (array) config('hub.mail.legal_entities', []),
            'permissionDefaults' => (array) config('hub.mail.mailbox_permission_defaults', []),
        ]);
    }

    public function store(MailboxRequest $request): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();
        $email = mb_strtolower(trim((string) $data['email_address']));

        if ($this->isAliasAddress($email, $this->organizationId($user))) {
            return redirect()->route('mail.admin.mailboxes.index')
                ->withErrors(['email_address' => 'Diese Adresse ist als Alias eines Postfachs geführt. Ein Alias ist kein Postfach und kann nicht als Postfach angelegt werden.'])
                ->withInput();
        }

        if (Mailbox::query()->where('email_address', $email)->exists()) {
            return redirect()->route('mail.admin.mailboxes.index')->withErrors(['email_address' => 'Dieses Postfach existiert bereits.'])->withInput();
        }

        $mailbox = Mailbox::query()->create([
            'organization_id' => $this->organizationId($user),
            'team_id' => $this->teamIdOrNull($data['team_id'] ?? null),
            'label' => trim((string) $data['label']),
            'email_address' => $email,
            'provider' => 'gmail',
            'legal_entity_code' => (string) $data['legal_entity_code'],
            'status' => 'not_configured',
            'status_reason' => 'OAuth-Verbindung noch nicht hergestellt',
        ]);
        $this->audit('admin.mailbox_created', $mailbox, [], ['email_address' => $email, 'legal_entity_code' => $data['legal_entity_code']]);

        return redirect()->route('mail.admin.mailboxes.index')->with('status', 'Postfach angelegt. Status: Nicht eingerichtet, Verbindung über Integrationen herstellen.');
    }

    public function update(MailboxRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $this->requireAdmin($request);
        $data = $request->validated();
        $before = $mailbox->only(['label', 'team_id', 'legal_entity_code', 'import_enabled']);

        $mailbox->forceFill([
            'label' => trim((string) $data['label']),
            'team_id' => $this->teamIdOrNull($data['team_id'] ?? null),
            'legal_entity_code' => (string) $data['legal_entity_code'],
            'import_enabled' => (bool) ($data['import_enabled'] ?? false),
        ])->save();
        $this->audit('admin.mailbox_updated', $mailbox, $before, $mailbox->only(['label', 'team_id', 'legal_entity_code', 'import_enabled']));

        return redirect()->route('mail.admin.mailboxes.index')->with('status', 'Postfach aktualisiert. Die E-Mail-Adresse ist nicht änderbar.');
    }

    public function storeAlias(AliasRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $this->requireAdmin($request);
        $data = $request->validated();
        $email = mb_strtolower(trim((string) $data['send_as_email']));

        if (Mailbox::query()->where('email_address', $email)->exists()) {
            return redirect()->route('mail.admin.mailboxes.index')->withErrors(['send_as_email' => 'Diese Adresse ist ein Postfach und kann nicht als Alias angelegt werden.'])->withInput();
        }

        $alias = MailboxAlias::query()->updateOrCreate(
            ['mailbox_id' => $mailbox->getKey(), 'send_as_email' => $email],
            [
                'display_name' => $data['display_name'] ?? null,
                'reply_to' => $data['reply_to'] ?? null,
                'legal_entity_code' => (string) $data['legal_entity_code'],
                'signature_key' => $data['signature_key'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                // Verifikation liefert der Alias-Abgleich mit Gmail (sendAs), nie die Eingabe.
                'verification_status' => 'unknown',
            ],
        );

        if ((bool) ($data['is_default'] ?? false)) {
            MailboxAlias::query()->where('mailbox_id', $mailbox->getKey())->whereKeyNot($alias->getKey())->update(['is_default' => false]);
        }

        $this->audit('admin.alias_saved', $alias, [], ['send_as_email' => $email]);

        return redirect()->route('mail.admin.mailboxes.index')->with('status', 'Alias gespeichert. Verifikationsstatus: unbekannt, bis der Gmail-Abgleich ihn bestätigt.');
    }

    public function destroyAlias(Request $request, Mailbox $mailbox, MailboxAlias $alias): RedirectResponse
    {
        $this->requireAdmin($request);

        if ((int) $alias->getAttribute('mailbox_id') !== (int) $mailbox->getKey()) {
            abort(404);
        }

        $this->audit('admin.alias_removed', $alias, $alias->only(['send_as_email']), []);
        $alias->delete();

        return redirect()->route('mail.admin.mailboxes.index')->with('status', 'Alias entfernt.');
    }

    public function updatePermission(MailboxPermissionRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();
        $userId = (int) $data['user_id'];

        if (! User::query()->where('organization_id', $this->organizationId($user))->whereKey($userId)->exists()) {
            return redirect()->route('mail.admin.mailboxes.index')->withErrors(['user_id' => 'Nutzer nicht gefunden.']);
        }

        $flags = [
            'can_read' => (bool) ($data['can_read'] ?? false),
            'can_draft' => (bool) ($data['can_draft'] ?? false),
            'can_send' => (bool) ($data['can_send'] ?? false),
            'can_assign' => (bool) ($data['can_assign'] ?? false),
            'can_view_bank_data' => (bool) ($data['can_view_bank_data'] ?? false),
        ];

        if ($flags === array_fill_keys(array_keys($flags), false)) {
            MailboxPermission::query()->where('mailbox_id', $mailbox->getKey())->where('user_id', $userId)->delete();
            $this->audit('admin.mailbox_permission_removed', $mailbox, [], ['user_id' => $userId]);

            return redirect()->route('mail.admin.mailboxes.index')->with('status', 'Postfachrecht entfernt.');
        }

        $permission = MailboxPermission::query()->updateOrCreate(
            ['mailbox_id' => $mailbox->getKey(), 'user_id' => $userId],
            $flags + ['granted_by' => $user->getKey(), 'granted_at' => now()],
        );
        $this->audit('admin.mailbox_permission_set', $permission, [], $flags + ['user_id' => $userId]);

        return redirect()->route('mail.admin.mailboxes.index')->with('status', 'Postfachrecht gespeichert.');
    }

    private function isAliasAddress(string $email, int $organizationId): bool
    {
        return MailboxAlias::query()
            ->where('send_as_email', $email)
            ->whereIn('mailbox_id', Mailbox::query()->allOrganizations()->where('organization_id', $organizationId)->select('id'))
            ->exists();
    }

    private function teamIdOrNull(mixed $teamId): ?int
    {
        $teamId = (int) $teamId;

        return $teamId > 0 && Team::query()->whereKey($teamId)->exists() ? $teamId : null;
    }
}
