<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration;

use App\Modules\Actions\Events\ExecutionVerified;
use App\Modules\Cases\Events\CaseOpened;
use App\Modules\Gmail\Events\MessageImported;
use App\Modules\Gmail\Events\SendVerificationCompleted;
use App\Modules\MailIntegration\Listeners\MarkCommunicationSent;
use App\Modules\MailIntegration\Listeners\OpenCaseFromImportedMessage;
use App\Modules\MailIntegration\Listeners\QueueAiClassification;
use App\Modules\MailIntegration\Listeners\UpdateCaseAfterVerification;
use App\Modules\MailIntegration\Services\CaseIntakeService;
use App\Modules\MailIntegration\Services\LiveApprovalWorkflow;
use App\Modules\MailIntegration\Services\LiveCandidateResolver;
use App\Modules\MailIntegration\Services\LiveCaseCommand;
use App\Modules\MailIntegration\Services\LiveDraftWorkflow;
use App\Modules\MailIntegration\Services\ReplyProposalService;
use App\Modules\MailUi\Contracts\ApprovalWorkflowInterface;
use App\Modules\MailUi\Contracts\CandidateResolverInterface;
use App\Modules\MailUi\Contracts\CaseCommandInterface;
use App\Modules\MailUi\Contracts\DraftWorkflowInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Modul MailIntegration: verdrahtet die Fachmodule der Mail- und Vorgangsbearbeitung untereinander und mit der
 * Oberfläche. Es enthält keine eigene Fachlogik, sondern ruft ausschließlich öffentliche Services der Module
 * Gmail, Cases, Sla, Actions, Ai und Drive auf.
 *
 * - MailUi-Verträge (CaseCommand, DraftWorkflow, ApprovalWorkflow, CandidateResolver) werden an Live-Dienste gebunden
 *   und ersetzen die Null-Implementierungen des Moduls MailUi (bindIf dort, bind hier).
 * - Gmail MessageImported: bestehende Vorgänge des Threads fortschreiben, sonst Vorgang mit Teilanliegen anlegen
 *   (Zuordnung nur über Kennungen, sonst Zuordnungsvorschlag).
 * - Gmail GmailReplyDetected: bindet das Modul Cases selbst (Kommunikationsbedarf erfüllt, Uhren gestoppt).
 * - Gmail SendVerificationCompleted: erst der verifizierte Versand (Label SENT) setzt den Kommunikationsstatus.
 * - Cases CaseOpened: KI-Klassifikation als Job auf mail-ai, nur bei MAIL_AI_ENABLED, nur Vorschlag.
 * - Cases P0-Regel: löst die EmergencyQueue (mail-high) direkt im CaseService aus (Modul Cases und Sla).
 * - Actions ExecutionVerified: Aufgabenstatus, Geschäftsstatus und Kommunikationsvorschlag; der Antwortentwurf
 *   nennt ausschließlich verifizierte Änderungen.
 * Zeitpläne liegen zentral in routes/console.php.
 */
class MailIntegrationServiceProvider extends ServiceProvider
{
    public const string MODULE = 'mailintegration';

    public function register(): void
    {
        $this->app->singleton(CaseIntakeService::class);
        $this->app->singleton(ReplyProposalService::class);

        $this->app->bind(CaseCommandInterface::class, LiveCaseCommand::class);
        $this->app->bind(DraftWorkflowInterface::class, LiveDraftWorkflow::class);
        $this->app->bind(ApprovalWorkflowInterface::class, LiveApprovalWorkflow::class);
        $this->app->bind(CandidateResolverInterface::class, LiveCandidateResolver::class);
    }

    public function boot(): void
    {
        $events = $this->app->make(EventDispatcher::class);
        $events->listen(MessageImported::class, OpenCaseFromImportedMessage::class);
        $events->listen(CaseOpened::class, QueueAiClassification::class);
        $events->listen(ExecutionVerified::class, UpdateCaseAfterVerification::class);
        $events->listen(SendVerificationCompleted::class, MarkCommunicationSent::class);
    }
}
