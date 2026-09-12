<?php

declare(strict_types=1);

namespace App\Modules\Ai;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Modules\Ai\Contracts\AiContextSourceInterface;
use App\Modules\Ai\Services\NotConfiguredAiProvider;
use App\Modules\Ai\Services\NullAiContextSource;
use App\Modules\Ai\Services\OpenAiProvider;
use App\Modules\Ai\Testing\FakeAiProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Ai: OpenAI-Adapter mit Schema-Validierung, Maskierung und Kostenzähler. Ausgaben sind stets Vorschläge.
 * Routen aus routes/modules/ai.php sind API- und Webhook-Routen ohne Domain-Bindung; Oberflächenrouten
 * gehören in routes/modules/mail.php (Domain mail.muellerhv.de, Modul MailUi).
 */
class AiServiceProvider extends ServiceProvider
{
    public const string MODULE = 'ai';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        if ($this->shouldBindFake('ai')) {
            $this->app->singleton(FakeAiProvider::class);
            $this->app->bind(AiProviderInterface::class, FakeAiProvider::class);
        } elseif ($this->shouldBindLive()) {
            $this->app->bind(AiProviderInterface::class, OpenAiProvider::class);
        } else {
            $this->app->bind(AiProviderInterface::class, NotConfiguredAiProvider::class);
        }

        // Dokumentkontext für die KI: Standard ohne Quelle. Das Modul Drive überschreibt die Bindung, wenn eingerichtet.
        if (! $this->app->bound(AiContextSourceInterface::class)) {
            $this->app->bind(AiContextSourceInterface::class, NullAiContextSource::class);
        }
    }

    /**
     * Live nur bei MAIL_AI_PROVIDER=live und vorhandenem API-Key und Modellname. Fehlt eines davon, bleibt die
     * Integration sichtbar "Nicht eingerichtet"; es wird kein Modellname angenommen.
     */
    private function shouldBindLive(): bool
    {
        $mode = config('hub.mail.providers.ai');
        $key = config('hub.ai.api_key');
        $model = config('hub.ai.model');

        return is_string($mode) && strtolower(trim($mode)) === 'live'
            && is_string($key) && trim($key) !== ''
            && is_string($model) && trim($model) !== '';
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

    /**
     * Fake nur in testing oder bei MAIL_<X>_PROVIDER=fake außerhalb von production (MailBootGuard verbietet fake in production).
     * Ohne Konfiguration wird eine Implementierung gebunden, die MailIntegrationNotConfiguredException wirft, damit die
     * Oberfläche "Nicht eingerichtet" zeigt statt eines Fehlers oder Erfolgs. Die Live-Implementierung bindet dieses Modul später.
     */
    private function shouldBindFake(string $integration): bool
    {
        if ($this->app->environment('testing')) {
            $mode = config('hub.mail.providers.'.$integration);

            return ! is_string($mode) || strtolower(trim($mode)) !== 'live';
        }

        $mode = config('hub.mail.providers.'.$integration);

        return is_string($mode) && strtolower(trim($mode)) === 'fake' && ! $this->app->isProduction();
    }
}
