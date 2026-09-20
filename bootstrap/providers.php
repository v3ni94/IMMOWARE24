<?php

use App\Modules\Actions\ActionsServiceProvider;
use App\Modules\Admin\AdminServiceProvider;
use App\Modules\Ai\AiServiceProvider;
use App\Modules\Api\ApiServiceProvider;
use App\Modules\Calendar\CalendarServiceProvider;
use App\Modules\Cases\CasesServiceProvider;
use App\Modules\Connector\ConnectorServiceProvider;
use App\Modules\Contacts\ContactsServiceProvider;
use App\Modules\Documents\DocumentsServiceProvider;
use App\Modules\Drive\DriveServiceProvider;
use App\Modules\Estate\EstateServiceProvider;
use App\Modules\Gmail\GmailServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Learning\LearningServiceProvider;
use App\Modules\Lexware\LexwareServiceProvider;
use App\Modules\Mail\MailServiceProvider;
use App\Modules\MailIntegration\MailIntegrationServiceProvider;
use App\Modules\MailUi\MailUiServiceProvider;
use App\Modules\Mcp\McpServiceProvider;
use App\Modules\Security\SecurityServiceProvider;
use App\Modules\Sla\SlaServiceProvider;
use App\Modules\Sync\SyncServiceProvider;
use App\Modules\Webhooks\WebhooksServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SecurityServiceProvider::class,
    ConnectorServiceProvider::class,
    DocumentsServiceProvider::class,
    ContactsServiceProvider::class,
    CalendarServiceProvider::class,
    EstateServiceProvider::class,
    SyncServiceProvider::class,
    ImportsServiceProvider::class,
    LearningServiceProvider::class,
    ApiServiceProvider::class,
    WebhooksServiceProvider::class,
    McpServiceProvider::class,
    AdminServiceProvider::class,
    // Mail- und Vorgangsbearbeitung (docs/mail/01-architekturentscheidung.md): additiv, nach AdminServiceProvider.
    MailServiceProvider::class,
    GmailServiceProvider::class,
    CasesServiceProvider::class,
    SlaServiceProvider::class,
    ActionsServiceProvider::class,
    LexwareServiceProvider::class,
    AiServiceProvider::class,
    DriveServiceProvider::class,
    MailUiServiceProvider::class,
    // Verdrahtung der Mail-Module untereinander und mit der Oberfläche, immer als letzter Provider.
    MailIntegrationServiceProvider::class,
];
