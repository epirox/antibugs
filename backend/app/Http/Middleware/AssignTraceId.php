<?php

namespace App\Http\Middleware;

use App\Http\Controllers\MetricsController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Observabilidad: propaga un trace_id por petición y lo inyecta en todos los logs
 * para poder correlacionar Frontend → Backend → DB en Loki.
 */
class AssignTraceId
{
    public const HEADER = 'X-Trace-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header(self::HEADER) ?: (string) Str::uuid();

        $request->attributes->set('trace_id', $traceId);
        Log::shareContext(['trace_id' => $traceId]);

        $start = microtime(true);
        $response = $next($request);

        $response->headers->set(self::HEADER, $traceId);

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $statusClass = intdiv($response->getStatusCode(), 100).'xx';

        // Contadores para Prometheus (ver MetricsController). Nunca deben romper la petición.
        rescue(function () use ($statusClass, $durationMs) {
            Cache::increment(MetricsController::CACHE_PREFIX."http_requests_total:{$statusClass}");
            Cache::increment(MetricsController::CACHE_PREFIX.'http_duration_ms_sum', $durationMs);
        }, report: false);

        Log::info('http.request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'user_id' => $request->user()?->id,
        ]);

        return $response;
    }
}
