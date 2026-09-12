<?php

use App\Modules\Admin\AdminServiceProvider;
use App\Modules\Api\ApiServiceProvider;
use App\Modules\Calendar\CalendarServiceProvider;
use App\Modules\Connector\ConnectorServiceProvider;
use App\Modules\Contacts\ContactsServiceProvider;
use App\Modules\Documents\DocumentsServiceProvider;
use App\Modules\Estate\EstateServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Mcp\McpServiceProvider;
use App\Modules\Security\SecurityServiceProvider;
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
    ApiServiceProvider::class,
    WebhooksServiceProvider::class,
    McpServiceProvider::class,
    AdminServiceProvider::class,
];
