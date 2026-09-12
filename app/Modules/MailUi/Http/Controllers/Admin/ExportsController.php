<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\Contacts\Models\Contact;
use App\Modules\MailUi\Contracts\SubjectAccessExportInterface;
use App\Modules\Security\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Auskunftsexport (DSGVO Art. 15) zu einem Kontakt: Nachrichten, Vorgänge, Aufgaben, Pläne mit maskierten Bankdaten.
 * Recht mail.export plus mail.admin, Re-Authentifizierung (Route mit 2fa.fresh). Der Export läuft asynchron; die Seite
 * zeigt die letzten Anforderungen und Fertigstellungen aus dem Auditlog.
 */
final class ExportsController extends AdminBaseController
{
    public function __construct(private readonly SubjectAccessExportInterface $exports) {}

    public function index(Request $request): View
    {
        $user = $this->requireAdmin($request);
        $this->requirePermission($user, 'mail.export');

        $query = AuditLog::query()
            ->where('organization_id', $this->organizationId($user))
            ->whereIn('action', ['mail.export.subject_access_requested', 'mail.export.subject_access_completed', 'mail.export.subject_access_failed']);
        $query->orderByDesc('occurred_at')->limit(50);

        return view('mail::admin.exports.index', [
            'title' => 'Auskunftsexport (DSGVO Art. 15)',
            'history' => $query->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $this->requirePermission($user, 'mail.export');

        $data = $request->validate([
            'contact_id' => ['required', 'integer', 'min:1'],
            'format' => ['required', Rule::in([SubjectAccessExportInterface::FORMAT_JSON, SubjectAccessExportInterface::FORMAT_CSV])],
        ]);

        $contact = Contact::query()->withoutGlobalScopes()
            ->where('organization_id', $this->organizationId($user))
            ->find((int) $data['contact_id']);

        if (! $contact instanceof Contact) {
            return redirect()->route('mail.admin.exports.index')->withErrors(['contact_id' => 'Kontakt in dieser Organisation nicht gefunden.']);
        }

        $result = $this->exports->request((int) $contact->getKey(), $user, (string) $data['format']);

        return $this->redirectWithResult('mail.admin.exports.index', $result);
    }
}
