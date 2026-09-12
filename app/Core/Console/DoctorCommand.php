<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Boot\BootGuard;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Mail\Boot\MailBootGuard;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Services\IntegrationStatusService;
use App\Modules\Mail\Services\MailFeatureFlags;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * hub:doctor: Statusbericht zu Konfiguration, Schreib-Flags, Queue, Datenbank, Redis, BootGuard und Adaptern.
 * Bricht nie ab, jeder Prüfpunkt meldet ok, warn oder fail. Exit-Code 1 nur bei fail.
 */
final class DoctorCommand extends Command
{
    /** Queue-Namen, auf die die Mail-Worker in compose.yaml, deploy/supervisor und deploy/systemd hören. */
    private const array MAIL_WORKER_QUEUES = ['mail-high', 'mail-sync', 'mail-ai'];

    protected $signature = 'hub:doctor {--json : Ausgabe als JSON}';

    protected $description = 'Prüft Konfiguration, Feature-Flags, Queue, Datenbank, Redis und BootGuard des Hubs.';

    /** @var array<int, array{check: string, status: string, detail: string}> */
    private array $rows = [];

    public function handle(ConfigRepository $config, BootGuard $guard, ConnectorManager $connectors): int
    {
        $this->rows = [];

        $this->checkEnvironment($config);
        $this->checkWriteFlags($config, $guard);
        $this->checkDatabase();
        $this->checkQueue($config);
        $this->checkRedis($config);
        $this->checkAdapters($connectors);
        $this->checkImports($config);
        $this->checkMail($config);

        if ($this->option('json')) {
            $this->line((string) json_encode($this->rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Prüfung', 'Status', 'Detail'], array_map(static fn (array $r): array => [$r['check'], strtoupper($r['status']), $r['detail']], $this->rows));
        }

        $failed = array_filter($this->rows, static fn (array $r): bool => $r['status'] === 'fail');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkEnvironment(ConfigRepository $config): void
    {
        $env = (string) $config->get('app.env');
        $debug = (bool) $config->get('app.debug');
        $this->add('app.env', 'ok', $env.($debug ? ', debug an' : ''));

        if ($env === 'production' && $debug) {
            $this->add('app.debug', 'fail', 'APP_DEBUG darf in Produktion nicht aktiv sein.');
        }

        $this->add('app.url', (string) $config->get('app.url') !== '' ? 'ok' : 'warn', (string) $config->get('app.url'));
        $this->add('hub.read.enabled', (bool) $config->get('hub.core.read.enabled') ? 'ok' : 'warn', $this->bool($config->get('hub.core.read.enabled')));
        $this->add('hub.api_keys.enabled', 'ok', $this->bool($config->get('hub.core.api_keys.enabled')));
        $this->add('hub.webhooks.enabled', 'ok', $this->bool($config->get('hub.webhooks.enabled')));
        $pepperSet = (string) $config->get('hub.security.hashing.pepper', '') !== '';
        $this->add('hub.hash_pepper', $pepperSet ? 'ok' : ((string) $config->get('app.env') === 'production' ? 'fail' : 'warn'), $pepperSet ? 'gesetzt' : 'HUB_HASH_PEPPER fehlt'.((string) $config->get('app.env') === 'production' ? ', in Produktion Pflicht (PepperedHasher bricht ab)' : ', Fallback APP_KEY'));
    }

    private function checkWriteFlags(ConfigRepository $config, BootGuard $guard): void
    {
        $core = (array) $config->get('hub.core', []);
        $this->add('write.enabled', 'ok', $this->bool(data_get($core, 'write.enabled')));
        $this->add('write.webdav_create_enabled', 'ok', $this->bool(data_get($core, 'write.webdav_create_enabled')));
        $this->add('write.dry_run', 'ok', $this->bool(data_get($core, 'write.dry_run')));

        foreach (BootGuard::HARD_LOCKED_FLAGS as $path => $env) {
            $value = data_get($core, $path);
            $this->add($env, $value ? 'fail' : 'ok', $value ? 'true, hart gesperrt, Anwendung startet nicht' : 'false (gesperrt)');
        }

        $violations = $guard->violations($core);
        $this->add('boot_guard', (bool) $config->get('hub.core.boot_guard', true) ? ($violations === [] ? 'ok' : 'fail') : 'warn',
            (bool) $config->get('hub.core.boot_guard', true) ? ($violations === [] ? 'aktiv, keine Verletzung' : 'Verletzungen: '.implode(', ', $violations)) : 'deaktiviert (HUB_BOOT_GUARD=false)');
    }

    private function checkDatabase(): void
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();
            $driver = $connection->getDriverName();
            $migrations = $connection->getSchemaBuilder()->hasTable('migrations') ? (int) $connection->table('migrations')->count() : 0;
            $this->add('database', 'ok', sprintf('%s, %d Migrationen', $driver, $migrations));
        } catch (Throwable $e) {
            $this->add('database', 'fail', 'Nicht erreichbar: '.$e::class);
        }
    }

    private function checkQueue(ConfigRepository $config): void
    {
        $connection = (string) $config->get('queue.default');
        $driver = (string) $config->get('queue.connections.'.$connection.'.driver', '');
        $status = $driver === 'sync' && (string) $config->get('app.env') === 'production' ? 'warn' : 'ok';
        $this->add('queue', $status, sprintf('%s (%s), Queues: %s', $connection, $driver, implode(', ', array_values((array) $config->get('hub.core.queues', [])))));
    }

    private function checkRedis(ConfigRepository $config): void
    {
        $needsRedis = in_array('redis', [
            (string) $config->get('queue.connections.'.(string) $config->get('queue.default').'.driver'),
            (string) $config->get('cache.stores.'.(string) $config->get('cache.default').'.driver'),
            (string) $config->get('session.driver'),
        ], true);

        try {
            $pong = Redis::connection()->command('ping');
            $this->add('redis', 'ok', is_string($pong) ? $pong : 'PONG');
        } catch (Throwable $e) {
            $this->add('redis', $needsRedis ? 'fail' : 'warn', 'Nicht erreichbar ('.$e::class.')'.($needsRedis ? ', aber für Queue, Cache oder Session konfiguriert' : ', nicht konfiguriert'));
        }
    }

    private function checkAdapters(ConnectorManager $connectors): void
    {
        $registered = $connectors->registeredNames();
        $missing = array_diff($connectors->knownNames(), $registered);
        $this->add('connector.adapters', $missing === [] ? 'ok' : 'warn', implode(', ', $registered).($missing !== [] ? ', fehlt: '.implode(', ', $missing) : ''));
    }

    private function checkImports(ConfigRepository $config): void
    {
        $path = (string) $config->get('hub.imports.drop_path', $config->get('hub.core.imports.drop_path'));

        if ($path === '') {
            $this->add('imports.drop_path', 'warn', 'HUB_IMPORT_DROP_PATH nicht gesetzt');

            return;
        }

        $this->add('imports.drop_path', is_dir($path) ? 'ok' : 'warn', is_dir($path) ? $path : $path.' (nicht vorhanden)');
    }

    /**
     * Mail- und Vorgangsbearbeitung (docs/mail/09-deployment.md): Flags (Standard false), Provider-Modus je Integration
     * (Nicht eingerichtet, Testbetrieb, Eingerichtet), Queues mail-high, mail-sync, mail-ai. fake in production ist ein
     * Fehler (MailBootGuard), Versand- oder Schreibflag in staging ebenso.
     */
    private function checkMail(ConfigRepository $config): void
    {
        $env = (string) $config->get('app.env');
        $flags = $this->laravel->make(MailFeatureFlags::class);
        $status = $this->laravel->make(IntegrationStatusService::class);

        $this->add('mail.domain', (string) $config->get('hub.mail.domain', '') !== '' ? 'ok' : 'warn', (string) $config->get('hub.mail.domain', ''));

        // Dieselbe Bewertung wie MailBootGuard::violations: staging oder production mit abweichender Domain sperrt die
        // Außenwirkungs-Flags. Ein Verstoß ist fail, denn der Boot würde abbrechen.
        $mailConfig = (array) $config->get('hub.mail', []);
        $violations = $this->laravel->make(MailBootGuard::class)->violations($mailConfig, $env);
        $productionDomain = strtolower(trim((string) ($mailConfig['production_domain'] ?? 'mail.muellerhv.de')));
        $domain = strtolower(trim((string) ($mailConfig['domain'] ?? '')));
        $stagingLike = $env === 'staging' || ($env === 'production' && $domain !== $productionDomain);

        foreach ($flags->all() as $flag => $enabled) {
            $envName = 'MAIL_'.strtoupper((string) $flag).'_ENABLED';
            $violated = array_filter($violations, static fn (string $v): bool => str_contains($v, $envName)) !== [];
            $state = $enabled ? ($violated ? 'fail' : 'warn') : 'ok';
            $detail = $enabled ? 'true'.($violated ? ', gesperrt (MailBootGuard: staging oder Domain ungleich '.$productionDomain.')' : ', aktiv nach Freigabe der Geschäftsführung') : 'false';
            $this->add('mail.flag.'.$envName, $state, $detail);
        }

        foreach ($violations as $violation) {
            if (! str_contains($violation, '_ENABLED')) {
                $this->add('mail.boot_guard', 'fail', $violation);
            }
        }

        $this->checkMailQueuesAndMailboxes($config, $stagingLike, $productionDomain);

        foreach ($status->overview() as $key => $row) {
            $mode = (string) $row['mode'];
            $state = 'ok';

            if ($mode === IntegrationStatusService::FAKE) {
                $state = $env === 'production' ? 'fail' : 'warn';
            }

            $this->add('mail.integration.'.$key, $state, $row['label'].($mode === IntegrationStatusService::FAKE && $env === 'production' ? ', fake in production verboten' : ''));
        }

        $queues = (array) $config->get('hub.mail.queues', []);
        $actionQueue = (string) $config->get('hub.actions.queue', $config->get('hub.mail.queues.high', 'mail-high'));
        $unknown = array_values(array_diff(array_map('strval', array_values($queues) + [99 => $actionQueue]), self::MAIL_WORKER_QUEUES));
        $this->add('mail.queues', $queues === [] ? 'warn' : ($unknown === [] ? 'ok' : 'fail'), implode(', ', array_values($queues)).($unknown === [] ? ' (eigener Worker für mail-high, siehe compose.yaml und deploy/)' : ' (ohne Worker: '.implode(', ', $unknown).'; Worker hören nur auf '.implode(', ', self::MAIL_WORKER_QUEUES).')'));
        $onCall = (array) $config->get('hub.sla.emergency.on_call_user_ids', []);
        $this->add('mail.on_call', $onCall === [] ? 'warn' : 'ok', $onCall === [] ? 'MAIL_ON_CALL_USER_IDS leer, keine 24/7-Betreuung eingerichtet' : count($onCall).' Bereitschaftsnutzer');
    }

    /**
     * Postfächer und Watch: in staging (oder production mit abweichender Domain) darf kein Postfach der
     * Produktionsdomain und keine Produktions-Redirect-URI eingetragen sein (docs/mail/09 Abschnitt 5). Aktiver Import
     * ohne Watch oder mit Ablauf unter 24 Stunden ist eine Warnung.
     */
    private function checkMailQueuesAndMailboxes(ConfigRepository $config, bool $stagingLike, string $productionDomain): void
    {
        try {
            $mailboxes = Mailbox::query()->allOrganizations()->get(['id', 'email_address', 'import_enabled']);
        } catch (Throwable) {
            $this->add('mail.mailboxes', 'warn', 'Tabelle mail_mailboxes nicht lesbar (Migrationen ausstehend?)');

            return;
        }

        if ($stagingLike) {
            $productionMailDomains = (array) $config->get('hub.mail.production_mail_domains', ['muellerhv.de', 'mueller-holding.ag']);
            $offending = $mailboxes->filter(static function (Mailbox $m) use ($productionMailDomains): bool {
                $host = strtolower((string) substr(strrchr((string) $m->getAttribute('email_address'), '@') ?: '', 1));

                return $host !== '' && in_array($host, array_map('strtolower', $productionMailDomains), true);
            });
            $this->add('mail.staging_mailboxes', $offending->isEmpty() ? 'ok' : 'fail', $offending->isEmpty()
                ? 'keine Postfächer der Produktionsdomain'
                : $offending->count().' Postfach/Postfächer mit Produktionsadresse außerhalb von '.$productionDomain.' (kein Produktions-Refresh-Token in Staging)');

            $redirect = (string) $config->get('hub.gmail.oauth.redirect_uris.production', '');
            $active = (string) $config->get('hub.gmail.oauth.redirect_uri', '');
            $this->add('mail.staging_redirect_uri', $active !== '' && $active === $redirect ? 'fail' : 'ok', $active !== '' && $active === $redirect ? 'Produktions-Redirect-URI aktiv in staging' : 'Redirect-URI passt zur Umgebung');
        }

        $importing = $mailboxes->filter(static fn (Mailbox $m): bool => (bool) $m->getAttribute('import_enabled'))->pluck('id')->all();

        if ($importing === []) {
            $this->add('mail.watch', 'ok', 'kein Postfach mit aktivem Import');

            return;
        }

        /** @var array<int, mixed> $expirations mailbox_id => watch_expiration */
        $expirations = [];

        foreach (MailSyncState::query()->whereIn('mailbox_id', $importing)->get() as $state) {
            if ($state instanceof MailSyncState) {
                $expirations[(int) $state->getAttribute('mailbox_id')] = $state->getAttribute('watch_expiration');
            }
        }

        $problems = [];

        foreach ($importing as $mailboxId) {
            $expiration = $expirations[(int) $mailboxId] ?? null;

            if (! $expiration instanceof \DateTimeInterface) {
                $problems[] = 'Postfach #'.$mailboxId.': kein Watch';
            } elseif ($expiration->getTimestamp() < time() + 86400) {
                $problems[] = 'Postfach #'.$mailboxId.': Watch läuft ab '.$expiration->format('d.m.Y H:i').' UTC';
            }
        }

        $this->add('mail.watch', $problems === [] ? 'ok' : 'warn', $problems === [] ? count($importing).' Postfach/Postfächer mit Watch über 24 h' : implode('; ', $problems));
    }

    private function add(string $check, string $status, string $detail): void
    {
        $this->rows[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    private function bool(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }
}
