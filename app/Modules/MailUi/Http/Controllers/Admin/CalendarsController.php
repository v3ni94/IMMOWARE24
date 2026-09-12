<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Core\Support\GermanDate;
use App\Modules\MailUi\Http\Requests\Admin\HolidayRequest;
use App\Modules\MailUi\Http\Requests\Admin\WorkCalendarRequest;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\WorkCalendar;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Arbeitszeiten (Wochenplan je Kalender, Zeitzone Europe/Berlin) und Feiertage (Datum, Bezeichnung, Region).
 * Die Uhren des Moduls Sla rechnen mit diesen Daten; Werte sind mit der Geschäftsführung zu bestätigen.
 */
final class CalendarsController extends AdminBaseController
{
    public const array DAYS = ['mon' => 'Montag', 'tue' => 'Dienstag', 'wed' => 'Mittwoch', 'thu' => 'Donnerstag', 'fri' => 'Freitag', 'sat' => 'Samstag', 'sun' => 'Sonntag'];

    public function index(Request $request): View
    {
        $this->requireAdmin($request);

        return view('mail::admin.calendars.index', [
            'title' => 'Arbeitszeiten und Feiertage',
            'calendars' => WorkCalendar::query()->with('holidays')->orderByDesc('is_default')->orderBy('name')->get(),
            'days' => self::DAYS,
        ]);
    }

    public function store(WorkCalendarRequest $request): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();
        $hours = $request->weeklyHours();

        $calendar = WorkCalendar::query()->updateOrCreate(
            ['organization_id' => $this->organizationId($user), 'name' => trim((string) $data['name'])],
            ['timezone' => 'Europe/Berlin', 'weekly_hours_json' => $hours, 'is_default' => (bool) ($data['is_default'] ?? false)],
        );

        if ((bool) ($data['is_default'] ?? false)) {
            WorkCalendar::query()->where('organization_id', $this->organizationId($user))->whereKeyNot($calendar->getKey())->update(['is_default' => false]);
        }

        $this->audit('admin.work_calendar_saved', $calendar, [], ['weekly_hours' => $hours]);

        return redirect()->route('mail.admin.calendars.index')->with('status', 'Arbeitskalender gespeichert.');
    }

    public function storeHoliday(HolidayRequest $request, WorkCalendar $calendar): RedirectResponse
    {
        $this->requireAdmin($request);
        $data = $request->validated();
        $date = GermanDate::parse((string) $data['holiday_date']);

        if ($date === null) {
            return redirect()->route('mail.admin.calendars.index')->withErrors(['holiday_date' => 'Datum im Format TT.MM.JJJJ angeben.'])->withInput();
        }

        $holiday = Holiday::query()->updateOrCreate(
            ['work_calendar_id' => $calendar->getKey(), 'holiday_date' => $date->toDateString()],
            ['label' => trim((string) $data['label']), 'region' => (string) ($data['region'] ?? 'NW'), 'created_at' => now()],
        );
        $this->audit('admin.holiday_saved', $holiday, [], ['holiday_date' => $date->toDateString(), 'label' => $data['label']]);

        return redirect()->route('mail.admin.calendars.index')->with('status', 'Feiertag gespeichert.');
    }

    public function destroyHoliday(Request $request, WorkCalendar $calendar, Holiday $holiday): RedirectResponse
    {
        $this->requireAdmin($request);

        if ((int) $holiday->getAttribute('work_calendar_id') !== (int) $calendar->getKey()) {
            abort(404);
        }

        $this->audit('admin.holiday_removed', $holiday, $holiday->only(['holiday_date', 'label']), []);
        $holiday->delete();

        return redirect()->route('mail.admin.calendars.index')->with('status', 'Feiertag entfernt.');
    }
}
