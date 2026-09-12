<?php

declare(strict_types=1);

use App\Modules\Api\Http\Controllers\CaseController;
use App\Modules\Api\Http\Controllers\ContactProposalController;
use App\Modules\Api\Http\Controllers\DirectoryController;
use App\Modules\Api\Http\Controllers\DocsController;
use App\Modules\Api\Http\Controllers\DocumentUploadController;
use App\Modules\Api\Http\Controllers\HealthController;
use App\Modules\Api\Http\Controllers\MetaController;
use App\Modules\Api\Http\Controllers\ResourceController;
use App\Modules\Api\Support\ResourceRegistry;
use Illuminate\Support\Facades\Route;

/*
 * Routen des Moduls Api. Wird ausschließlich vom ApiServiceProvider geladen.
 * Middleware-Aliase (api.auth, api.scope, api.throttle, api.idempotency) registriert der ApiServiceProvider.
 */

$readLimit = (int) config('hub.api.rate_limits.read_per_minute', 600);
$writeLimit = (int) config('hub.api.rate_limits.write_per_minute', 60);

Route::prefix('health')->name('health.')->group(static function (): void {
    Route::get('/', [HealthController::class, 'index'])->name('index');
    Route::get('database', [HealthController::class, 'database'])->name('database');
    Route::get('queue', [HealthController::class, 'queue'])->name('queue');
    Route::get('immoware', [HealthController::class, 'immoware'])->name('immoware');
});

Route::prefix('api/docs')->name('api.docs.')->middleware(['api.auth', 'api.throttle:'.$readLimit])->group(static function (): void {
    Route::get('/', [DocsController::class, 'html'])->name('html');
    Route::get('openapi.json', [DocsController::class, 'openapi'])->name('openapi');
});

Route::prefix('api/v1')->name('api.v1.')->middleware(['api.auth'])->group(static function () use ($readLimit, $writeLimit): void {
    Route::middleware(['api.throttle:'.$readLimit])->group(static function (): void {
        Route::get('/', [MetaController::class, 'root'])->name('root');
        Route::get('me', [MetaController::class, 'me'])->name('me');
        Route::get('sync/status', [MetaController::class, 'syncStatus'])->middleware('api.scope:sync:read')->name('sync.status');
        Route::get('capabilities', [MetaController::class, 'capabilities'])->middleware('api.scope:sync:read')->name('capabilities');

        // Status eines Upload-Antrags (09 3.5): documents:write oder sync:read.
        Route::get('documents/uploads/{uuid}', [DocumentUploadController::class, 'show'])
            ->middleware('api.scope:documents:write,sync:read')
            ->where('uuid', '[0-9a-fA-F-]{36}')
            ->name('documents.uploads.show');

        Route::middleware('api.scope:directory:read')->group(static function (): void {
            Route::get('directory', [DirectoryController::class, 'index'])->name('directory.index');
            Route::get('directory/search', [DirectoryController::class, 'search'])->name('directory.search');
        });

        /** @var ResourceRegistry $registry */
        $registry = app(ResourceRegistry::class);

        foreach ($registry->all() as $definition) {
            Route::middleware('api.scope:'.$definition->scope)->group(static function () use ($definition): void {
                Route::get($definition->name, [ResourceController::class, 'index'])
                    ->defaults('resource', $definition->name)
                    ->name($definition->name.'.index');
                Route::get($definition->name.'/{id}', [ResourceController::class, 'show'])
                    ->defaults('resource', $definition->name)
                    ->whereNumber('id')
                    ->name($definition->name.'.show');
            });
        }
    });

    Route::middleware(['api.throttle:'.$writeLimit, 'api.idempotency'])->group(static function (): void {
        Route::post('cases', [CaseController::class, 'store'])->middleware('api.scope:cases:write')->name('cases.store');
        Route::patch('cases/{id}', [CaseController::class, 'update'])->middleware('api.scope:cases:write')->whereNumber('id')->name('cases.update');
        Route::patch('contacts/{id}', [ContactProposalController::class, 'update'])->middleware('api.scope:contacts:write')->whereNumber('id')->name('contacts.propose');
        Route::post('documents', [DocumentUploadController::class, 'store'])->middleware('api.scope:documents:write')->name('documents.upload');
    });
});
