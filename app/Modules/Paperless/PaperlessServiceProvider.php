<?php

declare(strict_types=1);

namespace App\Modules\Paperless;

use App\Core\Contracts\Mail\PaperlessSourceInterface;
use App\Modules\Paperless\Services\NotConfiguredPaperlessSource;
use App\Modules\Paperless\Services\PaperlessProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Paperless: Anbindung an eine bestehende Paperless-ngx-Instanz (Dokumentenablage außerhalb von Immoware24),
 * lesend und schreibend (create-only). Ein technischer API-Token für den gesamten Hub, keine Verbindung je
 * Organisation. Objektzuordnung über ein in Paperless bereits gepflegtes Zusatzfeld.
 */
class PaperlessServiceProvider extends ServiceProvider
{
    public const string MODULE = 'paperless';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        if ($this->shouldBindLive()) {
            $this->app->bind(PaperlessSourceInterface::class, PaperlessProvider::class);
        } else {
            $this->app->bind(PaperlessSourceInterface::class, NotConfiguredPaperlessSource::class);
        }
    }

    /**
     * Live nur bei MAIL_PAPERLESS_PROVIDER=live und gesetzter Basis-URL sowie API-Token. Sonst sichtbar
     * "Nicht eingerichtet".
     */
    private function shouldBindLive(): bool
    {
        $mode = config('hub.mail.providers.paperless');
        $baseUrl = config('hub.paperless.base_url');
        $token = config('hub.paperless.api_token');

        return is_string($mode) && strtolower(trim($mode)) === 'live'
            && is_string($baseUrl) && trim($baseUrl) !== ''
            && is_string($token) && trim($token) !== '';
    }
}
