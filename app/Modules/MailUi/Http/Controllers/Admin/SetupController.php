<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Core\Support\GermanDate;
use App\Modules\Connector\Models\Organization;
use App\Modules\Estate\Models\Property;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Models\Team;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailUi\Http\Requests\Admin\SettingsRequest;
use App\Modules\MailUi\Http\Requests\Admin\WorkCalendarRequest;
use App\Modules\MailUi\Models\PropertyResponsibility;
use App\Modules\MailUi\Services\OrgSettings;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Einrichtungsassistent in neun Schritten: Organisation, Team, Arbeitszeiten, Feiertage, Postfächer, Zuständigkeiten,
 * Alarmempfänger, Freigabeberechtigte, Feature-Flags (nur Anzeige). Jeder Schritt speichert sofort in die Fachtabellen
 * bzw. mail_org_settings; der Fortschritt steht in mail_org_settings (Schlüssel setup).
 */
final class SetupController extends AdminBaseController
{
    public const array STEPS = [
        'organization' => 'Organisation',
        'team' => 'Team',
        'hours' => 'Arbeitszeiten',
        'holidays' => 'Feiertage',
        'mailboxes' => 'Postfächer',
        'responsibilities' => 'Zuständigkeiten',
        'alerts' => 'Alarmempfänger',
        'approvers' => 'Freigabeberechtigte',
        'flags' => 'Feature-Flags',
    ];

    public function __construct(
        private readonly OrgSettings $settings,
        private readonly MailFeatureFlags $flags,
    ) {}

    public function show(Request $request, string $step = 'organization'): View
    {
        $user = $this->requireAdmin($request);

        if (! array_key_exists($step, self::STEPS)) {
            abort(404);
        }

        $organizationId = $this->organizationId($user);
        $progress = $this->settings->get($organizationId, OrgSettings::SETUP);
        $keys = array_keys(self::STEPS);
        $index = (int) array_search($step, $keys, true);

        return view('mail::admin.setup.show', [
            'title' => 'Einrichtungsassistent: '.self::STEPS[$step],
            'step' => $step,
            'steps' => self::STEPS,
            'done' => (array) ($progress['done'] ?? []),
            'previous' => $index > 0 ? $keys[$index - 1] : null,
            'next' => $index < count($keys) - 1 ? $keys[$index + 1] : null,
            'organization' => Organization::query()->find($organizationId),
            'teams' => Team::query()->with('members.user')->orderBy('name')->get(),
            'users' => $this->organizationUsers($user),
            'calendar' => WorkCalendar::query()->where('is_default', true)->first() ?? WorkCalendar::query()->first(),
            'days' => CalendarsController::DAYS,
            'mailboxes' => Mailbox::query()->with('aliases')->orderBy('label')->get(),
            'legalEntities' => (array) config('hub.mail.legal_entities', []),
            'responsibilities' => PropertyResponsibility::query()->with(['property', 'team', 'user'])->limit(100)->get(),
            'properties' => Property::query()->orderBy('name')->limit(300)->get(['id', 'name', 'city']),
            'settings' => $this->settings->all($organizationId),
            'flags' => $this->flags->all(),
        ]);
    }

    public function store(Request $request, string $step): RedirectResponse
    {
        $user = $this->requireAdmin($request);

        if (! array_key_exists($step, self::STEPS)) {
            abort(404);
        }

        $organizationId = $this->organizationId($user);

        match ($step) {
            'organization' => $this->storeOrganization($request, $organizationId),
            'team' => $this->storeTeam($request, $user, $organizationId),
            'hours' => $this->storeHours($request, $organizationId),
            'holidays' => $this->storeHolidays($request, $organizationId),
            'mailboxes' => $this->storeMailbox($request, $organizationId),
            'responsibilities' => $this->storeResponsibility($request, $user, $organizationId),
            'alerts' => $this->storeSetting($request, $user, $organizationId, OrgSettings::ESCALATION_RECIPIENTS),
            'approvers' => $this->storeSetting($request, $user, $organizationId, OrgSettings::APPROVERS),
            default => null,
        };

        $progress = $this->settings->get($organizationId, OrgSettings::SETUP);
        $done = array_values(array_unique(array_merge((array) ($progress['done'] ?? []), [$step])));
        $this->settings->put($organizationId, OrgSettings::SETUP, ['done' => $done, 'updated_at' => now()->toIso8601String()], $user);
        $this->audit('admin.setup_step_saved', null, [], ['step' => $step]);

        $keys = array_keys(self::STEPS);
        $index = (int) array_search($step, $keys, true);
        $next = $keys[min($index + 1, count($keys) - 1)];

        return redirect()->route('mail.admin.setup.show', ['step' => $next])->with('status', 'Schritt "'.self::STEPS[$step].'" gespeichert.');
    }

    private function storeOrganization(Request $request, int $organizationId): void
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:200'],
            'default_legal_entity_code' => ['required', 'string', Rule::in(array_keys((array) config('hub.mail.legal_entities', [])))],
            'timezone_note' => ['nullable', 'string', 'max:120'],
        ]);
        $this->settings->put($organizationId, 'organization', [
            'display_name' => trim((string) $data['display_name']),
            'default_legal_entity_code' => (string) $data['default_legal_entity_code'],
            'display_timezone' => 'Europe/Berlin',
        ], $request->user() instanceof User ? $request->user() : null);
    }

    private function storeTeam(Request $request, User $user, int $organizationId): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'lead_user_id' => ['nullable', 'integer'],
            'escalation_user_id' => ['nullable', 'integer'],
            'members' => ['nullable', 'array', 'max:50'],
            'members.*' => ['integer', 'min:1'],
        ]);
        $name = trim((string) $data['name']);
        $team = Team::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'name' => $name],
            ['slug' => Str::slug($name), 'lead_user_id' => $data['lead_user_id'] ?? null, 'escalation_user_id' => $data['escalation_user_id'] ?? null],
        );

        foreach (array_map('intval', (array) ($data['members'] ?? [])) as $memberId) {
            if ($memberId > 0 && User::query()->where('organization_id', $organizationId)->whereKey($memberId)->exists()) {
                TeamMember::query()->firstOrCreate(['team_id' => $team->getKey(), 'user_id' => $memberId], ['team_role' => 'agent']);
            }
        }

        if ((int) ($data['lead_user_id'] ?? 0) > 0) {
            TeamMember::query()->updateOrCreate(['team_id' => $team->getKey(), 'user_id' => (int) $data['lead_user_id']], ['team_role' => 'lead']);
        }
    }

    private function storeHours(Request $request, int $organizationId): void
    {
        $formRequest = WorkCalendarRequest::createFrom($request);
        $formRequest->setContainer(app())->setRedirector(app('redirect'));
        $formRequest->validateResolved();
        $data = $formRequest->validated();
        $hours = $formRequest->weeklyHours();
        WorkCalendar::query()->where('organization_id', $organizationId)->update(['is_default' => false]);
        WorkCalendar::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'name' => trim((string) $data['name'])],
            ['timezone' => 'Europe/Berlin', 'weekly_hours_json' => $hours, 'is_default' => true],
        );
    }

    private function storeHolidays(Request $request, int $organizationId): void
    {
        $data = $request->validate([
            'holidays' => ['nullable', 'string', 'max:10000'],
            'region' => ['nullable', 'string', 'max:8'],
        ]);
        $calendar = WorkCalendar::query()->where('organization_id', $organizationId)->where('is_default', true)->first()
            ?? WorkCalendar::query()->create(['organization_id' => $organizationId, 'name' => 'Standard', 'timezone' => 'Europe/Berlin', 'weekly_hours_json' => [], 'is_default' => true]);

        foreach (preg_split('/\r?\n/', (string) ($data['holidays'] ?? '')) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, ';')) {
                continue;
            }

            [$dateRaw, $label] = array_map('trim', explode(';', $line, 2));
            $date = GermanDate::parse($dateRaw);

            if ($date === null || $label === '') {
                continue;
            }

            Holiday::query()->updateOrCreate(
                ['work_calendar_id' => $calendar->getKey(), 'holiday_date' => $date->toDateString()],
                ['label' => mb_substr($label, 0, 120), 'region' => (string) ($data['region'] ?? 'NW'), 'created_at' => CarbonImmutable::now()],
            );
        }
    }

    private function storeMailbox(Request $request, int $organizationId): void
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:120'],
            'email_address' => ['required', 'string', 'email', 'max:254'],
            'team_id' => ['nullable', 'integer'],
            'legal_entity_code' => ['required', 'string', Rule::in(array_keys((array) config('hub.mail.legal_entities', [])))],
            'aliases' => ['nullable', 'string', 'max:2000'],
        ]);
        $email = mb_strtolower(trim((string) $data['email_address']));

        $isAlias = MailboxAlias::query()->where('send_as_email', $email)
            ->whereIn('mailbox_id', Mailbox::query()->allOrganizations()->where('organization_id', $organizationId)->select('id'))
            ->exists();

        if ($isAlias) {
            throw ValidationException::withMessages(['email_address' => 'Diese Adresse ist ein Alias. Ein Alias ist kein Postfach.']);
        }

        $mailbox = Mailbox::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'email_address' => $email],
            ['label' => trim((string) $data['label']), 'team_id' => (int) ($data['team_id'] ?? 0) > 0 ? (int) $data['team_id'] : null, 'provider' => 'gmail', 'legal_entity_code' => (string) $data['legal_entity_code']],
        );

        foreach (preg_split('/[;,\s]+/', (string) ($data['aliases'] ?? '')) ?: [] as $alias) {
            $alias = mb_strtolower(trim($alias));

            if ($alias === '' || $alias === $email || filter_var($alias, FILTER_VALIDATE_EMAIL) === false || Mailbox::query()->where('email_address', $alias)->exists()) {
                continue;
            }

            MailboxAlias::query()->firstOrCreate(
                ['mailbox_id' => $mailbox->getKey(), 'send_as_email' => $alias],
                ['legal_entity_code' => (string) $data['legal_entity_code'], 'verification_status' => 'unknown'],
            );
        }
    }

    private function storeResponsibility(Request $request, User $user, int $organizationId): void
    {
        $data = $request->validate([
            'property_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
        ]);

        if ((int) ($data['property_id'] ?? 0) <= 0 || ! Property::query()->whereKey((int) $data['property_id'])->exists()) {
            return;
        }

        PropertyResponsibility::query()->updateOrCreate(
            ['property_id' => (int) $data['property_id'], 'role' => 'primary'],
            ['organization_id' => $organizationId, 'team_id' => (int) ($data['team_id'] ?? 0) > 0 ? (int) $data['team_id'] : null, 'user_id' => (int) ($data['user_id'] ?? 0) > 0 ? (int) $data['user_id'] : null, 'created_by' => $user->getKey()],
        );
    }

    private function storeSetting(Request $request, User $user, int $organizationId, string $key): void
    {
        $data = $request->validate(SettingsRequest::rulesFor($key));
        $this->settings->put($organizationId, $key, SettingsRequest::normalize($key, $data), $user);
    }
}
