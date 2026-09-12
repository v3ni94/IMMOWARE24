<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\ApiKeysController;
use App\Modules\Admin\Http\Controllers\AuditController;
use App\Modules\Admin\Http\Controllers\CapabilitiesController;
use App\Modules\Admin\Http\Controllers\ConflictsController;
use App\Modules\Admin\Http\Controllers\ConnectionsController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\DiscoveryController;
use App\Modules\Admin\Http\Controllers\DlqController;
use App\Modules\Admin\Http\Controllers\ExportController;
use App\Modules\Admin\Http\Controllers\ImportsController;
use App\Modules\Admin\Http\Controllers\MappingController;
use App\Modules\Admin\Http\Controllers\ProposalsController;
use App\Modules\Admin\Http\Controllers\RecordsController;
use App\Modules\Admin\Http\Controllers\RolesController;
use App\Modules\Admin\Http\Controllers\SyncController;
use App\Modules\Admin\Http\Controllers\SystemController;
use App\Modules\Admin\Http\Controllers\UsersController;
use App\Modules\Admin\Http\Controllers\WebhooksController;
use Illuminate\Support\Facades\Route;

/*
 * Routen des Moduls Admin. Wird ausschließlich vom AdminServiceProvider geladen und dort mit
 * Prefix /admin, Namensraum admin. und der Middleware-Gruppe admin (web, auth, admin.access, 2fa)
 * umschlossen. Weitere Admin-Seiten registrieren hier ihre Routen als admin.<bereich>.<aktion>.
 * Rechte je Aktion prüfen die Controller über requirePermission() oder Route::can('<recht>').
 * Sicherheitskritische Aktionen (API-Key anlegen, Rolle ändern, Connection-Status, Webhook anlegen) tragen
 * zusätzlich 2fa.fresh: TOTP-Bestätigung höchstens hub.security.totp.fresh_minutes alt (08-security.md 3.1).
 */
Route::get('/', DashboardController::class)->name('dashboard');

// Gruppe B
// Importe, Webhooks, API, Benutzer und Rollen, Auditlog, Discovery-Konsole, Export, System, Datenherkunft.
Route::prefix('imports')->name('imports.')->group(static function (): void {
    Route::get('/', [ImportsController::class, 'index'])->name('index');
    Route::get('schedules', [ImportsController::class, 'schedules'])->name('schedules.index');
    Route::post('schedules', [ImportsController::class, 'storeSchedule'])->name('schedules.store');
    Route::put('schedules/{schedule}', [ImportsController::class, 'updateSchedule'])->whereNumber('schedule')->name('schedules.update');
    Route::delete('schedules/{schedule}', [ImportsController::class, 'destroySchedule'])->whereNumber('schedule')->name('schedules.destroy');
    Route::get('{file}', [ImportsController::class, 'show'])->whereNumber('file')->name('show');
    Route::get('{file}/quarantine', [ImportsController::class, 'quarantine'])->whereNumber('file')->name('quarantine');
    Route::post('{file}/confirm-format', [ImportsController::class, 'confirmFormat'])->whereNumber('file')->name('confirm-format');
});

Route::prefix('webhooks')->name('webhooks.')->group(static function (): void {
    Route::get('/', [WebhooksController::class, 'index'])->name('index');
    Route::get('create', [WebhooksController::class, 'create'])->name('create');
    Route::post('/', [WebhooksController::class, 'store'])->middleware('2fa.fresh')->name('store');
    Route::get('deliveries', [WebhooksController::class, 'deliveries'])->name('deliveries.index');
    Route::get('dlq', [WebhooksController::class, 'dlq'])->name('dlq.index');
    Route::post('deliveries/{delivery}/redeliver', [WebhooksController::class, 'redeliver'])->whereNumber('delivery')->name('deliveries.redeliver');
    Route::get('{endpoint}/edit', [WebhooksController::class, 'edit'])->whereNumber('endpoint')->name('edit');
    Route::put('{endpoint}', [WebhooksController::class, 'update'])->whereNumber('endpoint')->name('update');
    Route::post('{endpoint}/deactivate', [WebhooksController::class, 'deactivate'])->whereNumber('endpoint')->name('deactivate');
    Route::post('{endpoint}/activate', [WebhooksController::class, 'activate'])->whereNumber('endpoint')->name('activate');
});

Route::prefix('api')->name('api.')->group(static function (): void {
    Route::get('/', [ApiKeysController::class, 'index'])->name('index');
    Route::get('create', [ApiKeysController::class, 'create'])->name('create');
    Route::post('/', [ApiKeysController::class, 'store'])->middleware('2fa.fresh')->name('store');
    Route::post('{key}/revoke', [ApiKeysController::class, 'revoke'])->whereNumber('key')->name('revoke');
});

Route::prefix('users')->name('users.')->group(static function (): void {
    Route::get('/', [UsersController::class, 'index'])->name('index');
    Route::get('create', [UsersController::class, 'create'])->name('create');
    Route::post('/', [UsersController::class, 'store'])->name('store');
    Route::get('{user}/edit', [UsersController::class, 'edit'])->whereNumber('user')->name('edit');
    Route::put('{user}', [UsersController::class, 'update'])->whereNumber('user')->middleware('2fa.fresh')->name('update');
    Route::post('{user}/reset-two-factor', [UsersController::class, 'resetTwoFactor'])->whereNumber('user')->name('reset-two-factor');
    Route::post('{user}/unlock', [UsersController::class, 'unlock'])->whereNumber('user')->name('unlock');
});

Route::get('roles', [RolesController::class, 'index'])->name('roles.index');

Route::prefix('audit')->name('audit.')->group(static function (): void {
    Route::get('/', [AuditController::class, 'index'])->name('index');
    Route::post('verify', [AuditController::class, 'verify'])->name('verify');
    Route::get('{log}', [AuditController::class, 'show'])->whereNumber('log')->name('show');
});

Route::prefix('discovery')->name('discovery.')->group(static function (): void {
    Route::get('/', [DiscoveryController::class, 'index'])->name('index');
    Route::get('{request}', [DiscoveryController::class, 'show'])->whereNumber('request')->name('show');
});

Route::prefix('export')->name('export.')->group(static function (): void {
    Route::get('/', [ExportController::class, 'index'])->name('index');
    Route::post('/', [ExportController::class, 'store'])->name('store');
    Route::get('{export}/download', [ExportController::class, 'download'])->whereNumber('export')->name('download');
});

Route::get('system', [SystemController::class, 'index'])->name('system.index');

foreach (RecordsController::ENTITIES as $entity => $model) {
    Route::get('records/'.$entity.'/{id}', [RecordsController::class, 'show'])->whereNumber('id')->defaults('entity', $entity)->name('records.'.$entity.'.show');
    Route::get('records/'.$entity.'/{id}/payload', [RecordsController::class, 'payload'])->whereNumber('id')->defaults('entity', $entity)->name('records.'.$entity.'.payload');
}
// Ende Gruppe B

// Gruppe A: Immoware-Verbindung, Capabilities, Synchronisation, Mapping, Konflikte, Fehlerqueue, Änderungsvorschläge.
Route::prefix('connections')->name('connections.')->group(function (): void {
    Route::get('/', [ConnectionsController::class, 'index'])->name('index');
    Route::get('/create', [ConnectionsController::class, 'create'])->name('create');
    Route::post('/', [ConnectionsController::class, 'store'])->name('store');
    Route::get('/{id}', [ConnectionsController::class, 'show'])->whereNumber('id')->name('show');
    Route::get('/{id}/edit', [ConnectionsController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::put('/{id}', [ConnectionsController::class, 'update'])->whereNumber('id')->name('update');
    Route::post('/{id}/probe', [ConnectionsController::class, 'probe'])->whereNumber('id')->name('probe');
    Route::post('/{id}/status', [ConnectionsController::class, 'status'])->whereNumber('id')->middleware('2fa.fresh')->name('status');
});

Route::get('/capabilities', [CapabilitiesController::class, 'index'])->name('capabilities.index');

Route::prefix('sync')->name('sync.')->group(function (): void {
    Route::get('/', [SyncController::class, 'index'])->name('index');
    Route::post('/start', [SyncController::class, 'start'])->name('start');
    Route::post('/bootstrap', [SyncController::class, 'bootstrap'])->name('bootstrap');
    Route::get('/runs', [SyncController::class, 'runs'])->name('runs');
    Route::get('/runs/{id}', [SyncController::class, 'showRun'])->whereNumber('id')->name('runs.show');
});

Route::prefix('mapping')->name('mapping.')->group(function (): void {
    Route::get('/', [MappingController::class, 'index'])->name('index');
    Route::get('/compare', [MappingController::class, 'compare'])->name('compare');
    Route::get('/create', [MappingController::class, 'create'])->name('create');
    Route::post('/review', [MappingController::class, 'review'])->name('review');
    Route::post('/', [MappingController::class, 'store'])->name('store');
    Route::get('/{id}', [MappingController::class, 'show'])->whereNumber('id')->name('show');
});

Route::prefix('conflicts')->name('conflicts.')->group(function (): void {
    Route::get('/', [ConflictsController::class, 'index'])->name('index');
    Route::get('/{id}', [ConflictsController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/{id}/resolve', [ConflictsController::class, 'resolve'])->whereNumber('id')->name('resolve');
});

Route::prefix('dlq')->name('dlq.')->group(function (): void {
    Route::get('/', [DlqController::class, 'index'])->name('index');
    Route::get('/{id}', [DlqController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/{id}/retry', [DlqController::class, 'retry'])->whereNumber('id')->name('retry');
    Route::post('/{id}/ignore', [DlqController::class, 'ignore'])->whereNumber('id')->name('ignore');
});

Route::prefix('proposals')->name('proposals.')->group(function (): void {
    Route::get('/', [ProposalsController::class, 'index'])->name('index');
    Route::get('/{id}', [ProposalsController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/{id}/transfer', [ProposalsController::class, 'transfer'])->whereNumber('id')->name('transfer');
});
// Ende Gruppe A
