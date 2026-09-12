<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Boot\BootGuard;
use App\Core\Console\DoctorCommand;
use App\Core\Database\BlueprintMacros;
use App\Core\Support\CorrelationId;
use App\Core\Support\HashedIdentifier;
use App\Core\Support\OrganizationContext;
use App\Core\Support\UrlGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CorrelationId::class);
        $this->app->singleton(OrganizationContext::class);
        $this->app->singleton(BootGuard::class);
        $this->app->singleton(HashedIdentifier::class);
        // SSRF-Schutz für ausgehende Ziel-URLs mit System-DNS; Tests tauschen den Resolver über den Container.
        $this->app->singleton(UrlGuard::class, static fn (): UrlGuard => UrlGuard::withSystemResolver());

        BlueprintMacros::register();
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class]);
        }

        $configured = (bool) config('hub.core.boot_guard', true);
        $environment = (string) $this->app->environment();

        if (! $configured && BootGuard::shouldRun($configured, $environment)) {
            Log::warning('HUB_BOOT_GUARD=false wird außerhalb von testing und local ignoriert; BootGuard läuft.', ['environment' => $environment]);
        }

        if (BootGuard::shouldRun($configured, $environment)) {
            $this->app->make(BootGuard::class)->assertSafe((array) config('hub.core', []));
        }
    }
}
