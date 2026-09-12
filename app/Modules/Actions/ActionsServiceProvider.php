<?php

declare(strict_types=1);

namespace App\Modules\Actions;

use App\Modules\Actions\Adapters\ImmowareTargetAdapter;
use App\Modules\Actions\Adapters\LexwareTargetAdapter;
use App\Modules\Actions\Adapters\ManualTargetAdapter;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Services\ActionAllowlist;
use App\Modules\Actions\Services\ActionOutboxWriter;
use App\Modules\Actions\Services\ActionPlanService;
use App\Modules\Actions\Services\ActionPolicy;
use App\Modules\Actions\Services\AdapterRegistry;
use App\Modules\Actions\Services\ApprovalService;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Actions\Services\ManualTaskFactory;
use App\Modules\Actions\Services\ManualTaskService;
use App\Modules\Actions\Services\PreconditionChecker;
use App\Modules\Actions\Support\DiffHasher;
use App\Modules\Actions\Support\IbanValidator;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Actions: Aktionspläne, Versionen, Vier-Augen-Freigaben, Ausführung über Adapter, Verifikation, Allowlist, Outbox.
 * Routen aus routes/modules/actions.php sind API- und Webhook-Routen ohne Domain-Bindung; Oberflächenrouten
 * gehören in routes/modules/mail.php (Domain mail.muellerhv.de, Modul MailUi).
 */
class ActionsServiceProvider extends ServiceProvider
{
    public const string MODULE = 'actions';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(IbanValidator::class);
        $this->app->singleton(DiffHasher::class);
        $this->app->singleton(ActionAllowlist::class);
        $this->app->singleton(ActionPolicy::class);
        $this->app->singleton(ActionOutboxWriter::class);
        $this->app->singleton(ManualTaskFactory::class);
        $this->app->singleton(PreconditionChecker::class);
        $this->app->singleton(ActionPlanService::class);
        $this->app->singleton(ApprovalService::class);
        $this->app->singleton(ExecutionService::class);
        $this->app->singleton(ManualTaskService::class);
        $this->app->singleton(AdapterRegistry::class, static function ($app): AdapterRegistry {
            $registry = new AdapterRegistry($app);
            // Gmail und Drive erhalten ihre Adapter in den Modulen Gmail und Drive (offen); ohne Adapter nicht ausführbar.
            $registry->register(TargetSystem::Immoware24, ImmowareTargetAdapter::class);
            $registry->register(TargetSystem::Lexware, LexwareTargetAdapter::class);
            $registry->register(TargetSystem::Manual, ManualTargetAdapter::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        // RunScheduledActionsJob (täglich 06:15 Europe/Berlin) ist zentral in routes/console.php eingeplant.

        $routes = base_path('routes/modules/'.self::MODULE.'.php');

        if (is_file($routes)) {
            $this->loadRoutesFrom($routes);
        }

        $views = resource_path('views/'.self::MODULE);

        if (is_dir($views)) {
            $this->loadViewsFrom($views, self::MODULE);
        }
    }
}
