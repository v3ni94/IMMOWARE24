<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

/**
 * Hilfsfunktionen für gefälschte DAV-Antworten in Connector-Tests.
 */
trait DavFixtures
{
    /**
     * @param  array<string, string>  $members  href => etag
     */
    protected function multistatus(array $members, bool $withCtag = true, bool $withSyncToken = true, bool $withReports = true, bool $withEtags = true): string
    {
        $root = '<D:response><D:href>/share/</D:href><D:propstat><D:prop>'
            .'<D:resourcetype><D:collection/></D:resourcetype>'
            .($withEtags ? '<D:getetag>"root"</D:getetag>' : '')
            .($withCtag ? '<CS:getctag>ctag-1</CS:getctag>' : '')
            .($withSyncToken ? '<D:sync-token>http://example.test/sync/1</D:sync-token>' : '')
            .($withReports ? '<D:supported-report-set><D:supported-report><D:report><D:sync-collection/></D:report></D:supported-report></D:supported-report-set>' : '')
            .'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';

        $items = '';

        foreach ($members as $href => $etag) {
            $items .= '<D:response><D:href>'.$href.'</D:href><D:propstat><D:prop><D:resourcetype/>'
                .($withEtags ? '<D:getetag>"'.$etag.'"</D:getetag>' : '')
                .'<D:getlastmodified>Fri, 11 Sep 2026 10:00:00 GMT</D:getlastmodified><D:getcontentlength>100</D:getcontentlength>'
                .'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }

        return '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:" xmlns:CS="http://calendarserver.org/ns/">'.$root.$items.'</D:multistatus>';
    }

    protected function syncCollectionReport(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:"><D:sync-token>http://example.test/sync/2</D:sync-token></D:multistatus>';
    }

    /**
     * @return array<string, string>
     */
    protected function davHeaders(): array
    {
        return ['DAV' => '1, 2, 3', 'Server' => 'TestDAV/1.0', 'Content-Type' => 'application/xml; charset=utf-8'];
    }
}
