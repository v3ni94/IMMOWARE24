<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Boot\BootGuard;
use App\Core\Console\DoctorCommand;
use App\Core\Database\BlueprintMacros;
use App\Core\Support\CorrelationId;
use App\Core\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CorrelationId::class);
        $this->app->singleton(OrganizationContext::class);
        $this->app->singleton(BootGuard::class);

        BlueprintMacros::register();
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class]);
        }

        if (config('hub.core.boot_guard', true)) {
            $this->app->make(BootGuard::class)->assertSafe((array) config('hub.core', []));
        }
    }
}
