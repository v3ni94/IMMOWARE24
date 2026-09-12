<?php

declare(strict_types=1);

namespace App\Modules\Drive;

use App\Core\Contracts\Mail\DocumentSourceInterface;
use App\Modules\Ai\Contracts\AiContextSourceInterface;
use App\Modules\Drive\Services\DriveAiContextSource;
use App\Modules\Drive\Services\DriveProvider;
use App\Modules\Drive\Services\NotConfiguredDocumentSource;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Drive: Google Drive v3 lesend (Suche, Metadaten, Ordner, Berechtigungen) und Dokumentreferenzen je Vorgang.
 * Routen aus routes/modules/drive.php sind API- und Webhook-Routen ohne Domain-Bindung; Oberflächenrouten
 * gehören in routes/modules/mail.php (Domain mail.muellerhv.de, Modul MailUi).
 */
class DriveServiceProvider extends ServiceProvider
{
    public const string MODULE = 'drive';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        if ($this->shouldBindLive()) {
            // DriveProvider wirft MailIntegrationNotConfiguredException, solange keine Verbindung mit Refresh-Token existiert.
            $this->app->bind(DocumentSourceInterface::class, DriveProvider::class);
            $this->app->bind(AiContextSourceInterface::class, DriveAiContextSource::class);
        } else {
            $this->app->bind(DocumentSourceInterface::class, NotConfiguredDocumentSource::class);
        }
    }

    /**
     * Live nur bei MAIL_DRIVE_PROVIDER=live und OAuth-Client-Daten. Sonst sichtbar "Nicht eingerichtet".
     */
    private function shouldBindLive(): bool
    {
        $mode = config('hub.mail.providers.drive');
        $clientId = config('hub.drive.oauth.client_id');
        $secret = config('hub.drive.oauth.client_secret');

        return is_string($mode) && strtolower(trim($mode)) === 'live'
            && is_string($clientId) && trim($clientId) !== ''
            && is_string($secret) && trim($secret) !== '';
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
