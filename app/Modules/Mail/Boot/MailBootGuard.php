<?php

declare(strict_types=1);

namespace App\Modules\Mail\Boot;

use RuntimeException;

/**
 * Startschutz des Mail-Moduls (docs/mail/01-architekturentscheidung.md, Abschnitt 9), analog App\Core\Boot\BootGuard:
 * 1. Fake-Provider (MAIL_*_PROVIDER=fake) sind in production verboten.
 * 2. In staging oder bei abweichender Domain dürfen Flags mit Außenwirkung (Versand, Immoware-Schreibpfad,
 *    Lexware-Schreibpfad) nicht true sein.
 * Läuft, wenn App\Core\Boot\BootGuard::shouldRun() es verlangt.
 */
final class MailBootGuard
{
    /**
     * @param  array<string, mixed>  $mailConfig  Inhalt von config('hub.mail')
     *
     * @throws RuntimeException
     */
    public function assertSafe(array $mailConfig, string $environment): void
    {
        $violations = $this->violations($mailConfig, $environment);

        if ($violations === []) {
            return;
        }

        throw new RuntimeException('Start verweigert (Mail-Modul): '.implode(' ', $violations));
    }

    /**
     * @param  array<string, mixed>  $mailConfig
     * @return array<int, string>
     */
    public function violations(array $mailConfig, string $environment): array
    {
        $environment = strtolower(trim($environment));
        $violations = [];

        if ($environment === 'production') {
            foreach ((array) ($mailConfig['providers'] ?? []) as $integration => $mode) {
                if (is_string($mode) && strtolower(trim($mode)) === 'fake') {
                    $violations[] = sprintf(
                        'MAIL_%s_PROVIDER=fake ist in production nicht erlaubt; Fake-Implementierungen dienen nur Tests.',
                        strtoupper((string) $integration),
                    );
                }
            }
        }

        $domain = strtolower(trim((string) ($mailConfig['domain'] ?? '')));
        $productionDomain = strtolower(trim((string) ($mailConfig['production_domain'] ?? 'mail.muellerhv.de')));
        $stagingLike = $environment === 'staging' || ($environment === 'production' && $domain !== $productionDomain);

        if ($stagingLike) {
            foreach ((array) ($mailConfig['staging_locked_flags'] ?? []) as $flag => $envName) {
                if ($this->isTruthy(data_get($mailConfig, 'flags.'.$flag))) {
                    $violations[] = sprintf(
                        '%s darf in staging oder außerhalb von %s nicht true sein (Versand und Schreibpfade gesperrt).',
                        (string) $envName,
                        $productionDomain,
                    );
                }
            }
        }

        return $violations;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $value === 1;
    }
}
