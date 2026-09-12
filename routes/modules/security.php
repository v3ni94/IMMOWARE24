<?php

declare(strict_types=1);

use App\Modules\Security\Http\Controllers\LoginController;
use App\Modules\Security\Http\Controllers\ReauthenticationController;
use App\Modules\Security\Http\Controllers\SessionController;
use App\Modules\Security\Http\Controllers\TwoFactorChallengeController;
use App\Modules\Security\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
 * Routen des Moduls Security. Wird ausschließlich vom SecurityServiceProvider geladen.
 * Middleware-Aliase des Moduls: auth.apikey, scope:<scope>, throttle.apikey[:n], 2fa.
 */
// Name login ohne Präfix, damit die Authenticate-Middleware von Laravel korrekt umleitet.
Route::middleware(['web', 'guest'])->get('/login', [LoginController::class, 'show'])->name('login');

Route::middleware('web')->name('security.')->group(static function (): void {
    Route::middleware('guest')->group(static function (): void {
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
    });

    Route::middleware('auth')->group(static function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/two-factor/challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
        Route::post('/two-factor/challenge', [TwoFactorChallengeController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');

        Route::get('/security/two-factor', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
        Route::post('/security/two-factor/confirm', [TwoFactorSetupController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
        Route::post('/security/two-factor/recovery-codes', [TwoFactorSetupController::class, 'regenerateRecoveryCodes'])->middleware('2fa')->name('two-factor.recovery-codes');
        Route::delete('/security/two-factor', [TwoFactorSetupController::class, 'disable'])->middleware('2fa')->name('two-factor.disable');

        Route::middleware('2fa')->group(static function (): void {
            // Erneute Authentifizierung (Passwort oder Code) für Aktionen mit Middleware 2fa.fresh.
            Route::get('/security/confirm', [ReauthenticationController::class, 'show'])->name('confirm.show');
            Route::post('/security/confirm', [ReauthenticationController::class, 'store'])->middleware('throttle:10,1')->name('confirm.store');

            Route::get('/security/sessions', [SessionController::class, 'index'])->name('sessions.index');
            Route::delete('/security/sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        });
    });
});
