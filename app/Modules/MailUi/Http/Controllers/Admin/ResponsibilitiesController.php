<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\Estate\Models\Property;
use App\Modules\Mail\Models\Team;
use App\Modules\MailUi\Http\Requests\Admin\ResponsibilityRequest;
use App\Modules\MailUi\Models\PropertyResponsibility;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Objektzuständigkeiten: Objekt (Spiegeldaten, nur per ID) zu Team oder Person, Hauptzuständigkeit und Vertretung.
 */
final class ResponsibilitiesController extends AdminBaseController
{
    public function index(Request $request): View
    {
        $user = $this->requireAdmin($request);

        return view('mail::admin.responsibilities.index', [
            'title' => 'Objektzuständigkeiten',
            'rows' => PropertyResponsibility::query()->with(['property', 'team', 'user'])->orderBy('property_id')->orderBy('role')->paginate($this->perPage()),
            'properties' => Property::query()->orderBy('name')->limit(500)->get(['id', 'name', 'immoware_object_number', 'city']),
            'teams' => Team::query()->orderBy('name')->get(),
            'users' => $this->organizationUsers($user),
        ]);
    }

    public function store(ResponsibilityRequest $request): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();

        if (! Property::query()->whereKey((int) $data['property_id'])->exists()) {
            return redirect()->route('mail.admin.responsibilities.index')->withErrors(['property_id' => 'Objekt nicht gefunden.'])->withInput();
        }

        $teamId = (int) ($data['team_id'] ?? 0) > 0 ? (int) $data['team_id'] : null;
        $userId = (int) ($data['user_id'] ?? 0) > 0 ? (int) $data['user_id'] : null;

        if ($teamId === null && $userId === null) {
            return redirect()->route('mail.admin.responsibilities.index')->withErrors(['team_id' => 'Team oder Person ist anzugeben.'])->withInput();
        }

        $row = PropertyResponsibility::query()->updateOrCreate(
            ['property_id' => (int) $data['property_id'], 'role' => (string) $data['role']],
            ['organization_id' => $this->organizationId($user), 'team_id' => $teamId, 'user_id' => $userId, 'created_by' => $user->getKey()],
        );
        $this->audit('admin.responsibility_set', $row, [], ['property_id' => $data['property_id'], 'team_id' => $teamId, 'user_id' => $userId, 'role' => $data['role']]);

        return redirect()->route('mail.admin.responsibilities.index')->with('status', 'Zuständigkeit gespeichert.');
    }

    public function destroy(Request $request, PropertyResponsibility $responsibility): RedirectResponse
    {
        $this->requireAdmin($request);
        $this->audit('admin.responsibility_removed', $responsibility, $responsibility->only(['property_id', 'team_id', 'user_id', 'role']), []);
        $responsibility->delete();

        return redirect()->route('mail.admin.responsibilities.index')->with('status', 'Zuständigkeit entfernt.');
    }
}
