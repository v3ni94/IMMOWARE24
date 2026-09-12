<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Console;

use App\Modules\MailUi\Contracts\SubjectAccessExportInterface;
use App\Modules\Security\Models\User;
use Illuminate\Console\Command;

/**
 * Auskunftsexport (DSGVO Art. 15) zu einem Kontakt anfordern. Der Befehl handelt immer im Namen eines Nutzers
 * (--user), dessen Recht mail.export geprüft wird; ohne Nutzer keine Ausführung. Erstellung asynchron (Queue low),
 * --sync verarbeitet den Job sofort im Prozess (Betrieb, Tests).
 */
final class SubjectAccessExportCommand extends Command
{
    protected $signature = 'mail:subject-access-export
        {contact : Kontakt-ID (contacts.id)}
        {--user= : Nutzer-ID, in deren Namen der Export angefordert wird (Recht mail.export)}
        {--format=json : json oder csv}
        {--sync : Export sofort im Prozess erstellen statt über die Queue}';

    protected $description = 'Auskunftsexport (DSGVO Art. 15): Nachrichten, Vorgänge, Aufgaben und Pläne zu einem Kontakt als JSON oder CSV, Bankdaten maskiert, auditiert.';

    public function handle(SubjectAccessExportInterface $exports): int
    {
        $userId = (int) $this->option('user');

        if ($userId <= 0) {
            $this->error('Bitte --user=<ID> angeben. Der Export wird nur im Namen eines Nutzers mit Recht mail.export erstellt.');

            return self::FAILURE;
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            $this->error('Nutzer nicht gefunden.');

            return self::FAILURE;
        }

        $result = $exports->request((int) $this->argument('contact'), $user, (string) $this->option('format'), (bool) $this->option('sync'));

        if (! $result->isOk()) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info($result->message);

        return self::SUCCESS;
    }
}
