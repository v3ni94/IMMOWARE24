<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CorrelationIdMiddleware
{
    public function __construct(private readonly CorrelationId $correlationId) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(CorrelationId::HEADER);
        $id = $this->correlationId->set(is_string($incoming) && $incoming !== '' ? $incoming : CorrelationId::generate());

        $request->attributes->set('correlation_id', $id);

        $response = $next($request);
        $response->headers->set(CorrelationId::HEADER, $id);

        return $response;
    }
}
