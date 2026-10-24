<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Observabilidad: expone métricas en formato de texto de Prometheus.
 * Los contadores HTTP se acumulan en Redis desde el middleware AssignTraceId.
 */
class MetricsController extends Controller
{
    public const CACHE_PREFIX = 'metrics:';

    public function __invoke(): Response
    {
        $lines = [];

        $lines[] = '# HELP antibugs_http_requests_total Total de peticiones HTTP por clase de estado.';
        $lines[] = '# TYPE antibugs_http_requests_total counter';
        foreach (['2xx', '3xx', '4xx', '5xx'] as $class) {
            $lines[] = sprintf(
                'antibugs_http_requests_total{status_class="%s"} %d',
                $class,
                (int) Cache::get(self::CACHE_PREFIX."http_requests_total:{$class}", 0)
            );
        }

        $lines[] = '# HELP antibugs_http_request_duration_ms_sum Suma de la duración de las peticiones en ms.';
        $lines[] = '# TYPE antibugs_http_request_duration_ms_sum counter';
        $lines[] = 'antibugs_http_request_duration_ms_sum '.(int) Cache::get(self::CACHE_PREFIX.'http_duration_ms_sum', 0);

        $lines[] = '# HELP antibugs_tickets_total Cantidad de tickets por estado.';
        $lines[] = '# TYPE antibugs_tickets_total gauge';
        $byStatus = Ticket::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        foreach (TicketStatus::cases() as $status) {
            $lines[] = sprintf('antibugs_tickets_total{status="%s"} %d', $status->value, $byStatus[$status->value] ?? 0);
        }

        $lines[] = '# HELP antibugs_tickets_created_last_hour Tickets creados en la última hora.';
        $lines[] = '# TYPE antibugs_tickets_created_last_hour gauge';
        $lines[] = 'antibugs_tickets_created_last_hour '.Ticket::query()->where('created_at', '>=', now()->subHour())->count();

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }
}
