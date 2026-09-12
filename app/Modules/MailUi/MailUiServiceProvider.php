<?php

declare(strict_types=1);

namespace App\Modules\MailUi;

use App\Modules\MailUi\Contracts\ApprovalWorkflowInterface;
use App\Modules\MailUi\Contracts\CandidateResolverInterface;
use App\Modules\MailUi\Contracts\CaseCommandInterface;
use App\Modules\MailUi\Contracts\DraftWorkflowInterface;
use App\Modules\MailUi\Services\CaseVisibility;
use App\Modules\MailUi\Services\DashboardMetrics;
use App\Modules\MailUi\Services\IntegrationOverview;
use App\Modules\MailUi\Services\NullApprovalWorkflow;
use App\Modules\MailUi\Services\NullCandidateResolver;
use App\Modules\MailUi\Services\NullCaseCommand;
use App\Modules\MailUi\Services\NullDraftWorkflow;
use App\Modules\MailUi\Services\OrgSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Modul MailUi: Controller, Requests und Blade-Views unter resources/views/mailui für die Oberfläche auf mail.muellerhv.de.
 * Routen aus routes/modules/mailui.php sind API- und Webhook-Routen ohne Domain-Bindung; Oberflächenrouten
 * gehören in routes/modules/mail.php (Domain mail.muellerhv.de, Modul MailUi).
 */
class MailUiServiceProvider extends ServiceProvider
{
    public const string MODULE = 'mailui';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(CaseVisibility::class);
        $this->app->singleton(DashboardMetrics::class);
        $this->app->singleton(IntegrationOverview::class);
        $this->app->singleton(OrgSettings::class);

        // Dünne Schnittstellen zu den Fachmodulen. Null-Implementierungen bis der Integrationsagent die Live-Dienste
        // (Cases, Gmail, Actions) bindet; bindIf lässt eine spätere Bindung durch andere Provider bestehen.
        $this->app->bindIf(CaseCommandInterface::class, NullCaseCommand::class);
        $this->app->bindIf(DraftWorkflowInterface::class, NullDraftWorkflow::class);
        $this->app->bindIf(ApprovalWorkflowInterface::class, NullApprovalWorkflow::class);
        $this->app->bindIf(CandidateResolverInterface::class, NullCandidateResolver::class);
    }

    public function boot(): void
    {
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
