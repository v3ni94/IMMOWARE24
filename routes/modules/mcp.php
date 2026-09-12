<?php

declare(strict_types=1);

use App\Modules\Mcp\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

/*
 * Routen des Moduls Mcp. Wird ausschließlich vom McpServiceProvider geladen.
 * Middleware-Aliase api.auth und api.throttle stammen aus dem ApiServiceProvider.
 * Scope-Prüfung je Tool erfolgt im ToolExecutor und zusätzlich im internen Sub-Request der Hub-API.
 */

$readLimit = (int) config('hub.api.rate_limits.read_per_minute', 600);

Route::prefix('api/v1/mcp')->name('api.v1.mcp.')->middleware(['api.auth', 'api.throttle:'.$readLimit])->group(static function (): void {
    Route::post('tools', [McpController::class, 'tools'])->name('tools');
    Route::post('call', [McpController::class, 'call'])->name('call');
});
