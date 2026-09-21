<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Lesezugriff auf die Feature-Flags des Mail-Moduls (config hub.mail.flags). Standard überall false.
 * Ein Flag allein schaltet nichts frei; Reihenfolge der Prüfung: Flag, Konfiguration, Recht, Freigabe, Ausführung, Verifikation.
 */
final class MailFeatureFlags
{
    public const string IMPORT = 'import';

    public const string AI = 'ai';

    public const string GMAIL_DRAFTS = 'gmail_drafts';

    public const string GMAIL_SEND = 'gmail_send';

    public const string IMMOWARE_WRITE = 'immoware_write';

    public const string LEXWARE_WRITE = 'lexware_write';

    public const string PAPERLESS_WRITE = 'paperless_write';

    public function __construct(private readonly Repository $config) {}

    public function enabled(string $flag): bool
    {
        return (bool) $this->config->get('hub.mail.flags.'.$flag, false);
    }

    public function importEnabled(): bool
    {
        return $this->enabled(self::IMPORT);
    }

    public function aiEnabled(): bool
    {
        return $this->enabled(self::AI);
    }

    public function gmailDraftsEnabled(): bool
    {
        return $this->enabled(self::GMAIL_DRAFTS);
    }

    public function gmailSendEnabled(): bool
    {
        return $this->enabled(self::GMAIL_SEND);
    }

    public function immowareWriteEnabled(): bool
    {
        return $this->enabled(self::IMMOWARE_WRITE);
    }

    public function lexwareWriteEnabled(): bool
    {
        return $this->enabled(self::LEXWARE_WRITE);
    }

    public function paperlessWriteEnabled(): bool
    {
        return $this->enabled(self::PAPERLESS_WRITE);
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $flags = (array) $this->config->get('hub.mail.flags', []);

        return array_map(static fn (mixed $value): bool => (bool) $value, $flags);
    }
}
