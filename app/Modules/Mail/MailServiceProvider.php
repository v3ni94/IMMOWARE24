<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Core\Boot\BootGuard;
use App\Modules\Mail\Boot\MailBootGuard;
use App\Modules\Mail\Http\Middleware\EnsureMailAccess;
use App\Modules\Mail\Http\Middleware\EnsureMailDomain;
use App\Modules\Mail\Http\Middleware\MailSecurityHeaders;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Services\IntegrationStatusService;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\Mail\Support\MailNavigation;
use App\Modules\Security\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modul Mail (Kern der Mail- und Vorgangsbearbeitung unter mail.muellerhv.de, docs/mail/01-architekturentscheidung.md).
 * Registriert Feature-Flags, Middleware-Gruppen mail, mail.push und mail.fresh, die domaingebundenen Routen aus
 * routes/modules/mail.php, das Layout layouts.mail, die Komponenten x-mail.* und merged die Rechte mail.* additiv in
 * die Security-Konfiguration. Bestehende Routen, Gruppen und Rechte bleiben unverändert.
 */
class MailServiceProvider extends ServiceProvider
{
    public const string MODULE = 'mail';

    public const string MIDDLEWARE_GROUP = 'mail';

    public const string MIDDLEWARE_GROUP_PUSH = 'mail.push';

    public const string MIDDLEWARE_GROUP_FRESH = 'mail.fresh';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->mergePermissionsIntoSecurity();

        $this->app->singleton(MailFeatureFlags::class);
        $this->app->singleton(IntegrationStatusService::class);
        $this->app->singleton(MailAccess::class);
        $this->app->singleton(MailBootGuard::class);
        $this->app->singleton(MailNavigation::class);
    }

    public function boot(): void
    {
        $this->runBootGuard();

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('mail.access', EnsureMailAccess::class);
        $router->aliasMiddleware('mail.domain', EnsureMailDomain::class);
        $router->aliasMiddleware('mail.headers', MailSecurityHeaders::class);
        // Reihenfolge: Session (web), Anmeldung, Hostprüfung, Rollen- und Rechteprüfung mit Mandantenkontext, danach 2FA.
        // mail.headers setzt die Content-Security-Policy als zweite Sperre hinter dem HtmlSanitizer.
        $router->middlewareGroup(self::MIDDLEWARE_GROUP, ['web', 'auth', 'mail.domain', 'mail.headers', 'mail.access', '2fa']);
        // Pub/Sub-Push: zustandslos, kein CSRF, Hostprüfung, globales Rate Limit, Rate Limit je Postfach oder IP.
        // Die JWT-Prüfung (mail.push.auth) ergänzt das Modul Gmail.
        $router->middlewareGroup(self::MIDDLEWARE_GROUP_PUSH, ['mail.domain', 'throttle:mail-push-global', 'throttle:mail-push']);
        // Freigaben, Versand, Bankdatenansicht, Integrationsänderungen, Export verlangen Re-Authentifizierung.
        $router->middlewareGroup(self::MIDDLEWARE_GROUP_FRESH, [self::MIDDLEWARE_GROUP, '2fa.fresh']);

        // Pub/Sub liefert aus wenigen Google-Adressen: Begrenzung je Postfach (emailAddress aus message.data), nicht je IP.
        // 429 wird protokolliert, damit ein verzögerter Import (Pub/Sub-Backoff) nicht unbemerkt bleibt.
        RateLimiter::for('mail-push', static function (Request $request): Limit {
            $key = self::pushRateLimitKey($request);

            return Limit::perMinute((int) config('hub.mail.push_rate_limit_per_minute', 600))
                ->by($key)
                ->response(static function (Request $request, array $headers) use ($key): Response {
                    Log::warning('mail.push.rate_limited', ['key' => hash('sha256', $key), 'retry_after' => $headers['Retry-After'] ?? null]);

                    return response('Too Many Requests', 429, $headers);
                });
        });

        // Globales Limit über alle Schlüssel (Schutz der Gesamtkapazität, unabhängig von Postfachadresse oder IP).
        RateLimiter::for('mail-push-global', static function (Request $request): Limit {
            return Limit::perMinute((int) config('hub.mail.push_rate_limit_global_per_minute', 3000))
                ->by('global')
                ->response(static function (Request $request, array $headers): Response {
                    Log::warning('mail.push.rate_limited', ['key' => 'global', 'retry_after' => $headers['Retry-After'] ?? null]);

                    return response('Too Many Requests', 429, $headers);
                });
        });

        $this->registerGates();
        $this->registerRoutes();
        $this->registerHostGuard();

        $views = resource_path('views/'.self::MODULE);

        if (is_dir($views)) {
            $this->loadViewsFrom($views, self::MODULE);
        }

        $this->registerViewComposer();
    }

    /**
     * Schlüssel des Push-Rate-Limits: Postfachadresse aus der Pub/Sub-Nutzlast, sonst Absender-IP.
     */
    public static function pushRateLimitKey(Request $request): string
    {
        $data = $request->input('message.data');

        if (is_string($data) && $data !== '') {
            $decoded = json_decode((string) base64_decode(strtr($data, '-_', '+/'), false), true);

            if (is_array($decoded) && is_string($decoded['emailAddress'] ?? null) && trim($decoded['emailAddress']) !== '') {
                return 'mailbox:'.strtolower(trim($decoded['emailAddress']));
            }
        }

        return 'ip:'.(string) $request->ip();
    }

    /**
     * Additiver Merge der Rechte mail.* in hub.security.permission_catalog und hub.security.permissions.
     * Muss in register() erfolgen, da SecurityServiceProvider::boot() die Gates aus der PermissionMap erzeugt.
     */
    private function mergePermissionsIntoSecurity(): void
    {
        $config = $this->app->make('config');

        $catalog = (array) $config->get('hub.security.permission_catalog', []);
        $mailCatalog = (array) $config->get('hub.mail.permission_catalog', []);
        $config->set('hub.security.permission_catalog', array_values(array_unique(array_merge($catalog, $mailCatalog))));

        $permissions = (array) $config->get('hub.security.permissions', []);

        foreach ((array) $config->get('hub.mail.permissions', []) as $role => $granted) {
            $existing = (array) ($permissions[$role] ?? []);

            if (in_array('*', $existing, true)) {
                continue;
            }

            $permissions[$role] = array_values(array_unique(array_merge($existing, (array) $granted)));
        }

        $config->set('hub.security.permissions', $permissions);
    }

    private function runBootGuard(): void
    {
        $configured = (bool) config('hub.core.boot_guard', true);
        $environment = (string) $this->app->environment();

        if (BootGuard::shouldRun($configured, $environment)) {
            $this->app->make(MailBootGuard::class)->assertSafe((array) config('hub.mail', []), $environment);
        }
    }

    /**
     * Dynamisches Gate mit Postfach-Argument: Gate::allows('mail.mailbox.view', $mailbox). Entscheidet über
     * mail_mailbox_permissions (MailAccess::canViewMailbox), nie allein über die Systemrolle.
     */
    private function registerGates(): void
    {
        Gate::define('mail.mailbox.view', function (User $user, Mailbox|int $mailbox): bool {
            $model = $mailbox instanceof Mailbox ? $mailbox : Mailbox::query()->withoutGlobalScopes()->find($mailbox);

            return $model instanceof Mailbox && $this->app->make(MailAccess::class)->canViewMailbox($user, $model);
        });
    }

    /**
     * Pfadtrennung auf dem Mail-Host (docs/mail/09 Abschnitt 3): Da die Mail-Oberfläche an der Wurzel des Hosts liegt,
     * ist eine Pfad-Allowlist in nginx nicht möglich. Deshalb beendet die Anwendung auf dem Mail-Host jede Route, die
     * nicht an die Mail-Domain gebunden ist, mit 404. Ausgenommen sind Anmeldung, 2FA, Sicherheitsseiten und die
     * Health-Endpunkte (hub.mail.host_allowed_route_prefixes). Admin-UI und /api/v1 des Hubs sind so unter
     * mail.muellerhv.de nicht erreichbar.
     */
    private function registerHostGuard(): void
    {
        Route::matched(static function (RouteMatched $event): void {
            $expected = strtolower(trim((string) config('hub.mail.domain', '')));

            if ($expected === '' || strtolower($event->request->getHost()) !== $expected) {
                return;
            }

            $route = $event->route;

            if (strtolower((string) $route->getDomain()) === $expected) {
                return;
            }

            $name = (string) $route->getName();
            $uri = trim($route->uri(), '/');

            // Mail-Routen ohne Domainbindung (Push-Endpunkt, Gruppe mail.push mit eigener Hostprüfung) bleiben erreichbar.
            if (str_starts_with($name, 'mail.') || in_array('mail.domain', $route->gatherMiddleware(), true)) {
                return;
            }

            foreach ((array) config('hub.mail.host_allowed_route_prefixes', ['login', 'security.', 'health', 'up']) as $prefix) {
                $prefix = (string) $prefix;

                if ($name === $prefix || str_starts_with($name, $prefix) || $uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                    return;
                }
            }

            abort(404);
        });
    }

    private function registerRoutes(): void
    {
        $routes = base_path('routes/modules/'.self::MODULE.'.php');
        $domain = (string) config('hub.mail.domain', 'mail.muellerhv.de');

        if (is_file($routes) && $domain !== '') {
            Route::domain($domain)
                ->middleware(self::MIDDLEWARE_GROUP)
                ->name(self::MODULE.'.')
                ->group($routes);
        }
    }

    private function registerViewComposer(): void
    {
        $this->app->make('view')->composer('layouts.mail', function (View $view): void {
            $request = $this->app->make('request');
            $user = $request->user();
            $routeName = $request->route()?->getName();

            $view->with('navigation', $this->app->make(MailNavigation::class)->items($user instanceof User ? $user : null, is_string($routeName) ? $routeName : null));
            $view->with('environment', (string) config('app.env', 'production'));
            $view->with('productName', (string) config('hub.mail.product_name', 'Mail und Vorgänge'));
            $view->with('operatorName', (string) config('hub.mail.operator', ''));
            $view->with('displayTimezone', (string) config('hub.mail.display_timezone', 'Europe/Berlin'));
            $view->with('shortcuts', (array) config('hub.mailui.shortcuts', []));
            $view->with('sendLocked', ! (bool) config('hub.mail.flags.gmail_send', false));
        });
    }
}
