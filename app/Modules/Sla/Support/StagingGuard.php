<?php

declare(strict_types=1);

namespace App\Modules\Sla\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Staging-Erkennung für Alarmkanäle, gleiche Regel wie MailBootGuard: Umgebung staging oder production außerhalb der
 * Betriebsdomain (hub.mail.domain ungleich hub.mail.production_domain). In Staging gehen Notfallalarme nie an reale
 * Empfänger, sondern nur an MAIL_EMERGENCY_TEST_RECIPIENT oder gar nicht (Status blocked).
 */
final class StagingGuard
{
    public function __construct(
        private readonly Repository $config,
        private readonly string $environment,
    ) {}

    public function stagingLike(): bool
    {
        $environment = strtolower(trim($this->environment));

        if ($environment === 'staging') {
            return true;
        }

        $domain = strtolower(trim((string) $this->config->get('hub.mail.domain', '')));
        $productionDomain = strtolower(trim((string) $this->config->get('hub.mail.production_domain', 'mail.muellerhv.de')));

        return $environment === 'production' && $domain !== $productionDomain;
    }

    /**
     * Testempfänger für umgeleitete Alarme in Staging, leer wenn nicht konfiguriert.
     */
    public function testRecipient(): string
    {
        return trim((string) $this->config->get('hub.sla.emergency.staging_test_recipient', ''));
    }
}
