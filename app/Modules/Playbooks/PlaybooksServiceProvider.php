<?php

declare(strict_types=1);

namespace App\Modules\Playbooks;

use App\Modules\Cases\Events\CaseOpened;
use App\Modules\Cases\Events\CaseStatusChanged;
use App\Modules\Playbooks\Console\RelearnPlaybooksCommand;
use App\Modules\Playbooks\Listeners\LearnFromClosedCase;
use App\Modules\Playbooks\Listeners\QueuePlaybookMatch;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Playbooks: Prozessdatenbank für Mail-Vorgänge. Lernt aus abgeschlossenen Vorgängen wiederkehrende
 * Bearbeitungsmuster (Prozessvorlagen) und schlägt sie bei ähnlichen neuen Vorgängen vor, zuerst regelbasiert
 * und kostenlos, nur bei unklarem Ergebnis mit KI-Unterstützung. Keine eigenen Oberflächenrouten: die
 * Verwaltung liegt im Modul MailUi (PlaybooksController), wie bei den übrigen Administrationsbereichen.
 */
class PlaybooksServiceProvider extends ServiceProvider
{
    public const string MODULE = 'playbooks';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RelearnPlaybooksCommand::class]);
        }

        $events = $this->app->make(Dispatcher::class);
        $events->listen(CaseOpened::class, QueuePlaybookMatch::class);
        $events->listen(CaseStatusChanged::class, LearnFromClosedCase::class);
    }
}
