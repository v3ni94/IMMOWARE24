<?php

declare(strict_types=1);

use App\Modules\Webhooks\Http\Controllers\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
 * Routen des Moduls Webhooks. Wird ausschließlich vom WebhooksServiceProvider geladen.
 * Middleware-Aliase api.* stellt das Api-Modul bereit.
 */
$readLimit = (int) config('hub.api.rate_limits.read_per_minute', 600);
$writeLimit = (int) config('hub.api.rate_limits.write_per_minute', 60);

Route::prefix('api/v1')->name('api.v1.webhook-endpoints.')->middleware(['api.auth', 'api.scope:webhooks:manage'])->group(static function () use ($readLimit, $writeLimit): void {
    Route::get('webhook-endpoints', [WebhookEndpointController::class, 'index'])->middleware('api.throttle:'.$readLimit)->name('index');
    Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store'])->middleware(['api.throttle:'.$writeLimit, 'api.idempotency'])->name('store');
    Route::delete('webhook-endpoints/{id}', [WebhookEndpointController::class, 'destroy'])->whereNumber('id')->middleware('api.throttle:'.$writeLimit)->name('destroy');
});
