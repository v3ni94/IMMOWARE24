<?php

declare(strict_types=1);

namespace App\Modules\Gmail;

use App\Core\Contracts\Mail\MailboxProviderInterface;
use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Http\Middleware\AuthenticatePubSubPush;
use App\Modules\Gmail\Mime\HeaderDecoder;
use App\Modules\Gmail\Mime\HtmlSanitizer;
use App\Modules\Gmail\Mime\MimeBuilder;
use App\Modules\Gmail\Mime\MimeParser;
use App\Modules\Gmail\Services\GmailApiClient;
use App\Modules\Gmail\Services\GmailProvider;
use App\Modules\Gmail\Services\NotConfiguredMailboxProvider;
use App\Modules\Gmail\Services\OAuth\GoogleOAuthService;
use App\Modules\Gmail\Services\Push\GoogleIdTokenVerifier;
use App\Modules\Gmail\Services\Push\PushEventService;
use App\Modules\Gmail\Services\QuotaCounter;
use App\Modules\Gmail\Services\Sync\SyncStateService;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Gmail: OAuth2, Watch und Push, History-Abgleich, Nachrichtenabruf, MIME, Entwürfe, Versandabgleich (nur Http-Facade).
 * Bindung des Postfachvertrags: testing oder MAIL_GMAIL_PROVIDER=fake → FakeGmailProvider; MAIL_GMAIL_PROVIDER=live mit
 * Client-ID und -Secret → GmailProvider; sonst NotConfiguredMailboxProvider (sichtbar "Nicht eingerichtet").
 * Routen aus routes/modules/gmail.php: Push-Endpunkt ohne Domain-Bindung, OAuth-Routen domaingebunden.
 * Zeitpläne: Watch-Erneuerung täglich, Abgleich alle reconcile_interval_minutes, Versandabgleich minütlich,
 * Push-Ereignisse älter als dedup_retention_days täglich entfernen.
 */
class GmailServiceProvider extends ServiceProvider
{
    public const string MODULE = 'gmail';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(HeaderDecoder::class);
        $this->app->singleton(MimeParser::class);
        $this->app->singleton(MimeBuilder::class);
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(QuotaCounter::class);
        $this->app->singleton(GoogleOAuthService::class);
        $this->app->singleton(GmailApiClient::class);
        $this->app->singleton(GoogleIdTokenVerifier::class);
        $this->app->singleton(PushEventService::class);
        $this->app->singleton(SyncStateService::class);

        if ($this->shouldBindFake('gmail')) {
            $this->app->singleton(FakeGmailProvider::class);
            $this->app->bind(GmailProviderInterface::class, FakeGmailProvider::class);
            $this->app->bind(MailboxProviderInterface::class, FakeGmailProvider::class);
        } elseif ($this->shouldBindLive()) {
            $this->app->singleton(GmailProvider::class);
            $this->app->bind(GmailProviderInterface::class, GmailProvider::class);
            $this->app->bind(MailboxProviderInterface::class, GmailProvider::class);
        } else {
            $this->app->bind(MailboxProviderInterface::class, NotConfiguredMailboxProvider::class);
            $this->app->bind(GmailProviderInterface::class, NotConfiguredMailboxProvider::class);
        }
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('mail.push.auth', AuthenticatePubSubPush::class);

        $routes = base_path('routes/modules/'.self::MODULE.'.php');

        if (is_file($routes)) {
            $this->loadRoutesFrom($routes);
        }

        $views = resource_path('views/'.self::MODULE);

        if (is_dir($views)) {
            $this->loadViewsFrom($views, self::MODULE);
        }

        // Zeitpläne (Watch-Erneuerung, Reconcile, Versandabgleich, Push-Bereinigung) liegen zentral in routes/console.php.
    }

    /**
     * Fake nur in testing (außer MAIL_GMAIL_PROVIDER=live) oder bei MAIL_GMAIL_PROVIDER=fake außerhalb von production
     * (MailBootGuard verbietet fake in production).
     */
    private function shouldBindFake(string $integration): bool
    {
        if ($this->app->environment('testing')) {
            $mode = config('hub.mail.providers.'.$integration);

            return ! is_string($mode) || strtolower(trim($mode)) !== 'live';
        }

        $mode = config('hub.mail.providers.'.$integration);

        return is_string($mode) && strtolower(trim($mode)) === 'fake' && ! $this->app->isProduction();
    }

    /**
     * Live nur mit MAIL_GMAIL_PROVIDER=live und hinterlegtem OAuth-Client; ohne Client bleibt es sichtbar
     * "Nicht eingerichtet" statt eines Fehlers beim ersten Aufruf.
     */
    private function shouldBindLive(): bool
    {
        $mode = config('hub.mail.providers.gmail');

        if (! is_string($mode) || strtolower(trim($mode)) !== 'live') {
            return false;
        }

        return trim((string) config('hub.gmail.oauth.client_id', '')) !== '' && trim((string) config('hub.gmail.oauth.client_secret', '')) !== '';
    }
}
