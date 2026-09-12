<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Core\Support\CorrelationId;
use App\Modules\Api\Directory\DirectoryService;
use App\Modules\Api\Directory\PhonebookXmlWriter;
use App\Modules\Api\Directory\VCardWriter;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Http\Query\ListQuery;
use App\Modules\Contacts\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/directory und /directory/search?q=. Formate json (Standard), vcf, xml.
 */
final class DirectoryController
{
    public function __construct(
        private readonly DirectoryService $directory,
        private readonly VCardWriter $vcards,
        private readonly PhonebookXmlWriter $xml,
        private readonly ListQuery $listQuery,
        private readonly CorrelationId $correlationId,
    ) {}

    public function index(Request $request): Response|JsonResponse
    {
        return $this->respond($request, null);
    }

    public function search(Request $request): Response|JsonResponse
    {
        $q = $request->query('q');

        if (! is_string($q) || mb_strlen(trim($q)) < 2) {
            throw ApiProblemException::badRequest('invalid_filter', 'Der Parameter q muss mindestens 2 Zeichen enthalten.');
        }

        return $this->respond($request, trim($q));
    }

    private function respond(Request $request, ?string $q): Response|JsonResponse
    {
        [$page, $perPage] = $this->listQuery->pagination($request);
        $paginator = $this->directory->paginate($q, $page, $perPage);
        $entries = [];

        foreach ($paginator->items() as $contact) {
            if ($contact instanceof Contact) {
                $entries[] = $this->directory->entry($contact);
            }
        }

        $format = strtolower((string) $request->query('format', 'json'));
        $now = CarbonImmutable::now()->toIso8601ZuluString();

        return match ($format) {
            'vcf', 'vcard' => new Response($this->vcards->write($entries), 200, [
                'Content-Type' => 'text/vcard; charset=utf-8',
                'Content-Disposition' => 'inline; filename="directory.vcf"',
                'X-Total-Count' => (string) $paginator->total(),
            ]),
            'xml' => new Response($this->xml->write($entries, $now), 200, [
                'Content-Type' => 'application/xml; charset=utf-8',
                'X-Total-Count' => (string) $paginator->total(),
            ]),
            'json' => new JsonResponse([
                'data' => array_map(static fn ($e): array => $e->toArray(), $entries),
                'meta' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'q' => $q,
                    'request_id' => $this->correlationId->current(),
                ],
                'links' => [
                    'next' => $paginator->hasMorePages() ? $paginator->url($paginator->currentPage() + 1) : null,
                    'prev' => $paginator->currentPage() > 1 ? $paginator->url($paginator->currentPage() - 1) : null,
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => throw ApiProblemException::badRequest('invalid_filter', 'format muss json, vcf oder xml sein.'),
        };
    }
}
