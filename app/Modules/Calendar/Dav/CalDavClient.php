<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Dav;

use App\Modules\Connector\Support\DavMultistatusParser;
use App\Modules\Contacts\Dav\AbstractDavClient;

/**
 * Lesender CalDAV-Client (RFC 4791): calendar-query (nur VEVENT), calendar-multiget, sync-collection.
 */
final class CalDavClient extends AbstractDavClient
{
    protected function namespacePrefix(): string
    {
        return 'cal';
    }

    protected function namespaceUri(): string
    {
        return DavMultistatusParser::NS_CALDAV;
    }

    protected function queryReportName(): string
    {
        return 'calendar-query';
    }

    protected function multigetReportName(): string
    {
        return 'calendar-multiget';
    }

    protected function dataElementName(): string
    {
        return 'calendar-data';
    }

    protected function queryFilterXml(): string
    {
        return '<cal:filter><cal:comp-filter name="VCALENDAR"><cal:comp-filter name="VEVENT"/></cal:comp-filter></cal:filter>';
    }
}
