<?php

declare(strict_types=1);

use App\Modules\Gmail\Http\Controllers\OAuthController;
use App\Modules\Gmail\Http\Controllers\PushController;
use App\Modules\Mail\MailServiceProvider;
use Illuminate\Support\Facades\Route;

/*
 * Routen des Moduls Gmail. Wird ausschließlich vom GmailServiceProvider geladen.
 *
 * 1. Pub/Sub-Push ohne Session und ohne CSRF: POST /mail/gmail/push (optional mit Pfad-Token). Middleware-Gruppe
 *    mail.push (Hostprüfung, Rate Limit) plus mail.push.auth (OIDC-JWT-Prüfung, 404 ohne konfiguriertes Topic).
 *    Abweichung vom Auftrag (/api/mail/gmail/push): der Bestandstest ReviewFixesTest verlangt für jede Route unter
 *    api/ die API-Key-Middleware api.auth, die Pub/Sub nicht bedienen kann; deshalb liegt der Endpunkt außerhalb von api/.
 * 2. OAuth-Routen der Integrationsverwaltung, domaingebunden (hub.mail.domain), Namensraum mail.integrations.gmail.,
 *    connect und revoke mit Re-Authentifizierung (mail.fresh), callback mit Sitzung (mail).
 */
Route::middleware([MailServiceProvider::MIDDLEWARE_GROUP_PUSH, 'mail.push.auth'])->group(static function (): void {
    Route::post('/mail/gmail/push', PushController::class)->name('mail.gmail.push');
    Route::post('/mail/gmail/push/{token}', PushController::class)->name('mail.gmail.push.token');
});

$domain = (string) config('hub.mail.domain', 'mail.muellerhv.de');

if ($domain !== '') {
    Route::domain($domain)->name('mail.integrations.gmail.')->group(static function (): void {
        Route::middleware(MailServiceProvider::MIDDLEWARE_GROUP_FRESH)->group(static function (): void {
            Route::post('/mail/integrations/gmail/{mailbox}/connect', [OAuthController::class, 'connect'])->name('connect');
            Route::post('/mail/integrations/gmail/{mailbox}/revoke', [OAuthController::class, 'revoke'])->name('revoke');
        });
        Route::middleware(MailServiceProvider::MIDDLEWARE_GROUP)->get('/mail/integrations/gmail/callback', [OAuthController::class, 'callback'])->name('callback');
    });
}
