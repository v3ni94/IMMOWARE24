<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Dav;

use App\Modules\Connector\Support\DavMultistatusParser;

/**
 * Lesender CardDAV-Client (RFC 6352): addressbook-query, addressbook-multiget, sync-collection.
 */
final class CardDavClient extends AbstractDavClient
{
    protected function namespacePrefix(): string
    {
        return 'card';
    }

    protected function namespaceUri(): string
    {
        return DavMultistatusParser::NS_CARDDAV;
    }

    protected function queryReportName(): string
    {
        return 'addressbook-query';
    }

    protected function multigetReportName(): string
    {
        return 'addressbook-multiget';
    }

    protected function dataElementName(): string
    {
        return 'address-data';
    }
}
