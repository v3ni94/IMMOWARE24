<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\Mail\Models\Team;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\MailUi\Http\Requests\Admin\TeamMemberRequest;
use App\Modules\MailUi\Http\Requests\Admin\TeamRequest;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Teams, Mitglieder und Team-Rollen (admin, lead, agent, approver, auditor) mit Rechtematrix aus hub.mail.team_roles.
 */
final class TeamsController extends AdminBaseController
{
    public function index(Request $request): View
    {
        $user = $this->requireAdmin($request);

        return view('mail::admin.teams.index', [
            'title' => 'Teams und Rollen',
            'teams' => Team::query()->with(['members.user', 'lead', 'escalationUser', 'mailboxes'])->orderBy('name')->get(),
            'users' => $this->organizationUsers($user),
            'roles' => MailAccess::TEAM_ROLES,
            'roleLabels' => (array) config('hub.mail.team_role_labels', []),
            'matrix' => (array) config('hub.mail.team_roles', []),
            'catalog' => (array) config('hub.mail.permission_catalog', []),
        ]);
    }

    public function store(TeamRequest $request): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();
        $name = trim((string) $data['name']);

        if (Team::query()->where('name', $name)->exists()) {
            return redirect()->route('mail.admin.teams.index')->withErrors(['name' => 'Ein Team mit diesem Namen existiert bereits.'])->withInput();
        }

        $team = Team::query()->create([
            'organization_id' => $this->organizationId($user),
            'name' => $name,
            'slug' => Str::slug($name),
            'lead_user_id' => $this->userIdOrNull($user, $data['lead_user_id'] ?? null),
            'escalation_user_id' => $this->userIdOrNull($user, $data['escalation_user_id'] ?? null),
        ]);
        $this->audit('admin.team_created', $team, [], ['name' => $name]);

        return redirect()->route('mail.admin.teams.index')->with('status', 'Team "'.$name.'" angelegt.');
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();
        $before = $team->only(['name', 'lead_user_id', 'escalation_user_id']);

        $team->forceFill([
            'name' => trim((string) $data['name']),
            'lead_user_id' => $this->userIdOrNull($user, $data['lead_user_id'] ?? null),
            'escalation_user_id' => $this->userIdOrNull($user, $data['escalation_user_id'] ?? null),
        ])->save();
        $this->audit('admin.team_updated', $team, $before, $team->only(['name', 'lead_user_id', 'escalation_user_id']));

        return redirect()->route('mail.admin.teams.index')->with('status', 'Team aktualisiert.');
    }

    public function storeMember(TeamMemberRequest $request, Team $team): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();
        $memberId = $this->userIdOrNull($user, $data['user_id']);

        if ($memberId === null) {
            return redirect()->route('mail.admin.teams.index')->withErrors(['user_id' => 'Nutzer nicht gefunden oder nicht in dieser Organisation.']);
        }

        $member = TeamMember::query()->updateOrCreate(
            ['team_id' => $team->getKey(), 'user_id' => $memberId],
            ['team_role' => $data['team_role'], 'active_from' => $data['active_from'] ?? null, 'active_until' => $data['active_until'] ?? null],
        );
        $this->audit('admin.team_member_set', $member, [], ['team_id' => $team->getKey(), 'user_id' => $memberId, 'team_role' => $data['team_role']]);

        return redirect()->route('mail.admin.teams.index')->with('status', 'Mitgliedschaft gespeichert.');
    }

    public function destroyMember(Request $request, Team $team, TeamMember $member): RedirectResponse
    {
        $this->requireAdmin($request);

        if ((int) $member->getAttribute('team_id') !== (int) $team->getKey()) {
            abort(404);
        }

        $this->audit('admin.team_member_removed', $member, $member->only(['team_id', 'user_id', 'team_role']), []);
        $member->delete();

        return redirect()->route('mail.admin.teams.index')->with('status', 'Mitgliedschaft entfernt. Postfachrechte bleiben bestehen und sind separat zu prüfen.');
    }

    private function userIdOrNull(User $actor, mixed $id): ?int
    {
        $id = (int) $id;

        if ($id <= 0) {
            return null;
        }

        return User::query()->where('organization_id', $this->organizationId($actor))->whereKey($id)->exists() ? $id : null;
    }
}
