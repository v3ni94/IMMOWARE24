<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailUi\Http\Requests\Admin\SettingsRequest;
use App\Modules\MailUi\Services\OrgSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Eskalationsempfänger, Bereitschaft, Kostenlimit KI, Aufbewahrung, Freigabeberechtigte (mail_org_settings).
 * Feature-Flags werden nur angezeigt (Umgebung, kein UI-Schalter).
 */
final class SettingsController extends AdminBaseController
{
    public function __construct(
        private readonly OrgSettings $settings,
        private readonly MailFeatureFlags $flags,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->requireAdmin($request);

        return view('mail::admin.settings.index', [
            'title' => 'Eskalation, Bereitschaft, KI-Kosten, Aufbewahrung',
            'settings' => $this->settings->all($this->organizationId($user)),
            'labels' => OrgSettings::LABELS,
            'users' => $this->organizationUsers($user),
            'flags' => $this->flags->all(),
        ]);
    }

    public function update(SettingsRequest $request, string $key): RedirectResponse
    {
        $user = $this->requireAdmin($request);

        if (! array_key_exists($key, OrgSettings::LABELS) || $key === OrgSettings::SETUP) {
            abort(404);
        }

        $before = $this->settings->get($this->organizationId($user), $key);
        $value = $request->valueFor($key);
        $this->settings->put($this->organizationId($user), $key, $value, $user);
        $this->audit('admin.setting_updated', null, ['key' => $key, 'value' => $before], ['key' => $key, 'value' => $value]);

        return redirect()->route('mail.admin.settings.index')->with('status', OrgSettings::LABELS[$key].' gespeichert.');
    }
}
