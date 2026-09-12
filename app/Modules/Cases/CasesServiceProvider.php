<?php

declare(strict_types=1);

namespace App\Modules\Cases;

use App\Modules\Cases\Events\InboundMessageSynced;
use App\Modules\Cases\Events\OutboundReplyDetected;
use App\Modules\Cases\Listeners\HandleGmailReplyDetected;
use App\Modules\Cases\Listeners\HandleInboundMessageSynced;
use App\Modules\Cases\Services\AssignmentService;
use App\Modules\Cases\Services\CaseNumberGenerator;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Cases\Services\CaseStatusLogger;
use App\Modules\Cases\Services\CloseConditionChecker;
use App\Modules\Cases\Services\DelegationService;
use App\Modules\Cases\Services\LockService;
use App\Modules\Cases\Services\TaskService;
use App\Modules\Cases\StateMachines\BusinessStateMachine;
use App\Modules\Cases\StateMachines\CommunicationStateMachine;
use App\Modules\Cases\StateMachines\ProcessingStateMachine;
use App\Modules\Cases\StateMachines\TaskStateMachine;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Cases: Vorgänge, Teilanliegen, Nachrichtenzuordnung, Referenzen über IDs, Zuweisungsentscheidungen, Aufgaben,
 * drei Statusdimensionen mit Übergangsmatrix, Bearbeitungssperren, Vertretung, Teamlast.
 * Ereignisse des Moduls Gmail (MessageSynced, GmailReplyDetected) werden, sobald die Klassen existieren, an dieselben
 * Listener gebunden wie die Fallback-Ereignisse dieses Moduls.
 * Routen aus routes/modules/cases.php sind API- und Webhook-Routen ohne Domain-Bindung; Oberflächenrouten
 * gehören in routes/modules/mail.php (Domain mail.muellerhv.de, Modul MailUi).
 */
class CasesServiceProvider extends ServiceProvider
{
    public const string MODULE = 'cases';

    /** Erwartete Ereignisklassen des Moduls Gmail (Eigenschaft $message oder $messageId). */
    public const string GMAIL_EVENT_MESSAGE_SYNCED = 'App\\Modules\\Gmail\\Events\\MessageSynced';

    public const string GMAIL_EVENT_REPLY_DETECTED = 'App\\Modules\\Gmail\\Events\\GmailReplyDetected';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(ProcessingStateMachine::class);
        $this->app->singleton(CommunicationStateMachine::class);
        $this->app->singleton(BusinessStateMachine::class);
        $this->app->singleton(TaskStateMachine::class);
        $this->app->singleton(CaseStatusLogger::class);
        $this->app->singleton(CaseNumberGenerator::class);
        $this->app->singleton(CloseConditionChecker::class);
        $this->app->singleton(AssignmentService::class);
        $this->app->singleton(TaskService::class);
        $this->app->singleton(LockService::class);
        $this->app->singleton(DelegationService::class);
        $this->app->singleton(CaseService::class);
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

        $events = $this->app->make(EventDispatcher::class);
        $events->listen(InboundMessageSynced::class, HandleInboundMessageSynced::class);
        $events->listen(OutboundReplyDetected::class, HandleGmailReplyDetected::class);

        if (class_exists(self::GMAIL_EVENT_MESSAGE_SYNCED)) {
            $events->listen(self::GMAIL_EVENT_MESSAGE_SYNCED, HandleInboundMessageSynced::class);
        }

        if (class_exists(self::GMAIL_EVENT_REPLY_DETECTED)) {
            $events->listen(self::GMAIL_EVENT_REPLY_DETECTED, HandleGmailReplyDetected::class);
        }
    }
}
