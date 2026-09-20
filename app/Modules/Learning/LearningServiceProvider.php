<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Modules\Learning\Console\LearnCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Learning: Lernphase Immoware24. Erkundet lesend über die belegten Zugangswege (WebDAV, CardDAV, CalDAV,
 * Dateiexporte) die aktuelle Struktur, vergleicht sie mit dem vorigen Lauf und kann eine KI-Auswertung anstoßen.
 * Keine eigenen Oberflächenrouten: die Verwaltung liegt im Modul Admin (LearningController, docs/architecture/01),
 * wie bei den übrigen Immoware24-nahen Bereichen (Discovery, Mapping, Proposals, Imports).
 */
class LearningServiceProvider extends ServiceProvider
{
    public const string MODULE = 'learning';

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
            $this->commands([LearnCommand::class]);
        }
    }
}
