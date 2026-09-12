<?php

declare(strict_types=1);

use App\Modules\Drive\Http\Controllers\DriveOAuthController;
use App\Modules\Mail\MailServiceProvider;
use Illuminate\Support\Facades\Route;

/*
 * Routen des Moduls Drive. Wird ausschließlich vom DriveServiceProvider geladen.
 *
 * OAuth-Routen der Integrationsverwaltung, domaingebunden (hub.mail.domain), Namensraum mail.integrations.drive.
 * (von config hub.mailui.integration_routes.drive referenziert): connect und revoke mit Re-Authentifizierung
 * (mail.fresh), callback mit Sitzung (mail). Eine Verbindung je Organisation, deshalb ohne Routenparameter.
 */
$domain = (string) config('hub.mail.domain', 'mail.muellerhv.de');

if ($domain !== '') {
    Route::domain($domain)->name('mail.integrations.drive.')->group(static function (): void {
        Route::middleware(MailServiceProvider::MIDDLEWARE_GROUP_FRESH)->group(static function (): void {
            Route::post('/mail/integrations/drive/connect', [DriveOAuthController::class, 'connect'])->name('connect');
            Route::post('/mail/integrations/drive/revoke', [DriveOAuthController::class, 'revoke'])->name('revoke');
        });
        Route::middleware(MailServiceProvider::MIDDLEWARE_GROUP)->get('/mail/integrations/drive/callback', [DriveOAuthController::class, 'callback'])->name('callback');
    });
}
