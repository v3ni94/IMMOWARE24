<?php

declare(strict_types=1);

use App\Modules\MailUi\Http\Controllers\Admin\CalendarsController;
use App\Modules\MailUi\Http\Controllers\Admin\ExportsController;
use App\Modules\MailUi\Http\Controllers\Admin\MailboxesController;
use App\Modules\MailUi\Http\Controllers\Admin\PlaybooksController;
use App\Modules\MailUi\Http\Controllers\Admin\ResponsibilitiesController;
use App\Modules\MailUi\Http\Controllers\Admin\SettingsController;
use App\Modules\MailUi\Http\Controllers\Admin\SetupController;
use App\Modules\MailUi\Http\Controllers\Admin\SlaRulesController;
use App\Modules\MailUi\Http\Controllers\Admin\TeamsController;
use App\Modules\MailUi\Http\Controllers\ApprovalController;
use App\Modules\MailUi\Http\Controllers\CaseController;
use App\Modules\MailUi\Http\Controllers\DashboardController;
use App\Modules\MailUi\Http\Controllers\DraftController;
use App\Modules\MailUi\Http\Controllers\InboxController;
use App\Modules\MailUi\Http\Controllers\IntegrationsController;
use App\Modules\MailUi\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

/*
 * Oberflächenrouten der Mail- und Vorgangsbearbeitung. Wird ausschließlich vom MailServiceProvider geladen und dort mit
 * Route::domain(config('hub.mail.domain')), Namensraum mail. und der Middleware-Gruppe mail (web, auth, mail.domain,
 * mail.access, 2fa) umschlossen. Sicherheitskritische Aktionen (Freigabe, Ablehnung, Bankdaten einblenden, Versand)
 * tragen zusätzlich 2fa.fresh. Es gibt bewusst keine Route für Sammelfreigabe oder Sammelversand; die Sammelaktion
 * der Inbox erlaubt nur Zuordnung, Kategorie und interne Aufgabe (BulkActionRequest).
 */
Route::get('/', DashboardController::class)->name('dashboard');
Route::redirect('/mail', '/')->name('home');

Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
Route::post('/inbox/bulk', [InboxController::class, 'bulk'])->name('inbox.bulk');
Route::get('/work', [WorkController::class, 'index'])->name('work.index');
Route::get('/tasks', [WorkController::class, 'index'])->name('tasks.index');

Route::prefix('/cases')->name('cases.')->group(static function (): void {
    Route::get('/', static fn () => redirect()->route('mail.inbox.index'))->name('index');
    Route::get('/{case}', [CaseController::class, 'show'])->whereNumber('case')->name('show');
    Route::post('/{case}/assign', [CaseController::class, 'assign'])->whereNumber('case')->name('assign');
    Route::post('/{case}/category', [CaseController::class, 'category'])->whereNumber('case')->name('category');
    Route::post('/{case}/status', [CaseController::class, 'status'])->whereNumber('case')->name('status');
    Route::post('/{case}/tasks', [CaseController::class, 'task'])->whereNumber('case')->name('tasks.store');
    // Manuelle Bestätigung einer Aufgabe aus einem Aktionsplan (Zielsystem nicht schreibfähig): Status manually_confirmed, Reauth.
    Route::post('/{case}/tasks/{task}/confirm', [CaseController::class, 'confirmTask'])->whereNumber('case')->whereNumber('task')->middleware('2fa.fresh')->name('tasks.confirm');
    Route::post('/{case}/notes', [CaseController::class, 'note'])->whereNumber('case')->name('notes.store');
    Route::post('/{case}/candidates/confirm', [CaseController::class, 'confirmCandidate'])->whereNumber('case')->name('candidates.confirm');
    Route::post('/{case}/drafts', [DraftController::class, 'store'])->whereNumber('case')->name('drafts.store');
    Route::put('/{case}/drafts/{draft}', [DraftController::class, 'update'])->whereNumber('case')->whereNumber('draft')->name('drafts.update');
    Route::post('/{case}/drafts/{draft}/review', [DraftController::class, 'review'])->whereNumber('case')->whereNumber('draft')->name('drafts.review');
    Route::post('/{case}/drafts/{draft}/approve', [DraftController::class, 'approve'])->whereNumber('case')->whereNumber('draft')->middleware('2fa.fresh')->name('drafts.approve');
    Route::post('/{case}/drafts/{draft}/send', [DraftController::class, 'send'])->whereNumber('case')->whereNumber('draft')->middleware('2fa.fresh')->name('drafts.send');
});

Route::prefix('/approvals')->name('approvals.')->group(static function (): void {
    Route::get('/', [ApprovalController::class, 'index'])->name('index');
    Route::get('/{plan}', [ApprovalController::class, 'show'])->whereNumber('plan')->name('show');
    Route::post('/{plan}/approve', [ApprovalController::class, 'approve'])->whereNumber('plan')->middleware('2fa.fresh')->name('approve');
    Route::post('/{plan}/reject', [ApprovalController::class, 'reject'])->whereNumber('plan')->middleware('2fa.fresh')->name('reject');
    Route::post('/{plan}/reveal-bank-data', [ApprovalController::class, 'reveal'])->whereNumber('plan')->middleware('2fa.fresh')->name('reveal');
    // Identitätsprüfung dokumentieren (Pflicht vor Freigabe einer Bankänderung) und offene Schritte erneut einplanen.
    Route::post('/{plan}/identity-check', [ApprovalController::class, 'identityCheck'])->whereNumber('plan')->middleware('2fa.fresh')->name('identity_check');
    Route::post('/{plan}/retry', [ApprovalController::class, 'retry'])->whereNumber('plan')->middleware('2fa.fresh')->name('retry');
});

Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');

Route::prefix('/admin')->name('admin.')->group(static function (): void {
    Route::get('/', static fn () => redirect()->route('mail.admin.teams.index'))->name('index');
    Route::get('/teams', [TeamsController::class, 'index'])->name('teams.index');
    Route::post('/teams', [TeamsController::class, 'store'])->name('teams.store');
    Route::put('/teams/{team}', [TeamsController::class, 'update'])->whereNumber('team')->name('teams.update');
    Route::post('/teams/{team}/members', [TeamsController::class, 'storeMember'])->whereNumber('team')->name('teams.members.store');
    Route::delete('/teams/{team}/members/{member}', [TeamsController::class, 'destroyMember'])->whereNumber('team')->whereNumber('member')->name('teams.members.destroy');

    Route::get('/mailboxes', [MailboxesController::class, 'index'])->name('mailboxes.index');
    Route::post('/mailboxes', [MailboxesController::class, 'store'])->name('mailboxes.store');
    Route::put('/mailboxes/{mailbox}', [MailboxesController::class, 'update'])->whereNumber('mailbox')->name('mailboxes.update');
    Route::post('/mailboxes/{mailbox}/aliases', [MailboxesController::class, 'storeAlias'])->whereNumber('mailbox')->name('mailboxes.aliases.store');
    Route::delete('/mailboxes/{mailbox}/aliases/{alias}', [MailboxesController::class, 'destroyAlias'])->whereNumber('mailbox')->whereNumber('alias')->name('mailboxes.aliases.destroy');
    Route::post('/mailboxes/{mailbox}/permissions', [MailboxesController::class, 'updatePermission'])->whereNumber('mailbox')->name('mailboxes.permissions.update');

    Route::get('/responsibilities', [ResponsibilitiesController::class, 'index'])->name('responsibilities.index');
    Route::post('/responsibilities', [ResponsibilitiesController::class, 'store'])->name('responsibilities.store');
    Route::delete('/responsibilities/{responsibility}', [ResponsibilitiesController::class, 'destroy'])->whereNumber('responsibility')->name('responsibilities.destroy');

    Route::get('/calendars', [CalendarsController::class, 'index'])->name('calendars.index');
    Route::post('/calendars', [CalendarsController::class, 'store'])->name('calendars.store');
    Route::post('/calendars/{calendar}/holidays', [CalendarsController::class, 'storeHoliday'])->whereNumber('calendar')->name('calendars.holidays.store');
    Route::delete('/calendars/{calendar}/holidays/{holiday}', [CalendarsController::class, 'destroyHoliday'])->whereNumber('calendar')->whereNumber('holiday')->name('calendars.holidays.destroy');

    Route::get('/sla', [SlaRulesController::class, 'index'])->name('sla.index');
    Route::post('/sla', [SlaRulesController::class, 'store'])->name('sla.store');
    Route::post('/sla/{rule}/toggle', [SlaRulesController::class, 'toggle'])->whereNumber('rule')->name('sla.toggle');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/{key}', [SettingsController::class, 'update'])->where('key', '[a-z_]+')->name('settings.update');

    // Prozessdatenbank (Modul Playbooks): Prozessvorlagen prüfen und aktivieren, offene Abgleiche entscheiden.
    Route::get('/playbooks', [PlaybooksController::class, 'index'])->name('playbooks.index');
    Route::get('/playbooks/matches', [PlaybooksController::class, 'matches'])->name('playbooks.matches');
    Route::post('/playbooks/matches/{match}/decide', [PlaybooksController::class, 'decide'])->whereNumber('match')->name('playbooks.matches.decide');
    Route::get('/playbooks/{playbook}', [PlaybooksController::class, 'show'])->whereNumber('playbook')->name('playbooks.show');
    Route::post('/playbooks/{playbook}/activate', [PlaybooksController::class, 'activate'])->whereNumber('playbook')->name('playbooks.activate');
    Route::post('/playbooks/{playbook}/retire', [PlaybooksController::class, 'retire'])->whereNumber('playbook')->name('playbooks.retire');

    // Auskunftsexport (DSGVO Art. 15): Anforderung asynchron, nur mit mail.export, Re-Auth, auditiert.
    Route::get('/exports', [ExportsController::class, 'index'])->name('exports.index');
    Route::post('/exports', [ExportsController::class, 'store'])->middleware('2fa.fresh')->name('exports.store');

    Route::get('/setup/{step?}', [SetupController::class, 'show'])->where('step', '[a-z]+')->name('setup.show');
    Route::post('/setup/{step}', [SetupController::class, 'store'])->where('step', '[a-z]+')->name('setup.store');
});
