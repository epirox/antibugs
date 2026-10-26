# 5 · Observabilidad y monitoreo (Grafana)

## 5.1 Objetivo

Cumplir la sección 7 de la consigna: **logs estructurados con `trace_id`**, **métricas** de rendimiento y de negocio, y un **escenario de alerta** (más del 5 % de errores 5xx en 5 minutos) con la capacidad de rastrear la falla hasta la capa responsable.

## 5.2 Stack

```
                 scrape /metrics (15 s)
 ┌──────────┐ ◀──────────────────────── ┌────────────┐      ┌─────────┐
 │ backend  │                           │ Prometheus │ ───▶ │         │
 │ Laravel  │  stdout JSON              │  :9090     │      │ Grafana │
 │          │ ─────┐                    │  + alertas │      │  :3000  │
 └──────────┘      │                    └────────────┘      │         │
                   ▼                                        │ dash-   │
 ┌──────────┐  ┌──────────┐             ┌────────────┐      │ board   │
 │ docker   │  │ Promtail │ ──────────▶ │   Loki     │ ───▶ │ provi-  │
 │ logs     │  │          │  push       │   :3100    │      │ sionado │
 └──────────┘  └──────────┘             └────────────┘      └─────────┘
```

| Componente | Imagen | Rol |
|---|---|---|
| **Prometheus** | `prom/prometheus:v2.55.1` | Scrapea `backend:8000/metrics` cada 15 s y evalúa las reglas de alerta. |
| **Loki** | `grafana/loki:3.3.2` | Almacena e indexa los logs por etiquetas (`service`, `container`, `level`). |
| **Promtail** | `grafana/promtail:3.3.2` | Descubre los contenedores del proyecto vía `docker.sock` y envía su stdout a Loki. |
| **Grafana** | `grafana/grafana:11.4.0` | Visualización. Datasources y dashboard se **provisionan automáticamente** desde `observability/grafana/provisioning/`. |

Todo vive en el *profile* `observability` de `docker-compose.yml`, así el stack principal sigue siendo liviano:

```bash
docker compose --profile observability up -d
```

| URL | Credenciales |
|---|---|
| Grafana → http://localhost:3000 | `admin` / `admin` (variables `GRAFANA_USER` / `GRAFANA_PASSWORD`) |
| Prometheus → http://localhost:9090 | — |
| Métricas crudas → http://localhost:8000/metrics | — |

Al iniciar sesión, Grafana abre directamente el dashboard **AntiBugs · Monitoreo del servicio** (carpeta *AntiBugs*).

## 5.3 Instrumentación en el backend

### Logs estructurados (Monolog · JSON)

Canal `json` en `config/logging.php` (`JsonFormatter` → stdout). El middleware `AssignTraceId`:

1. Toma el header `X-Trace-Id` si el cliente lo envía; si no, genera un UUID.
2. Lo inyecta en **todos** los logs de la petición con `Log::shareContext(['trace_id' => …])`.
3. Lo devuelve en la respuesta (`X-Trace-Id`) para que el frontend / soporte pueda citarlo.
4. Registra una línea `http.request` por petición con método, ruta, status, duración y usuario.

```json
{"message":"http.request","context":{"trace_id":"c194bc96-…","method":"GET","path":"api/v1/reports/kpis","status":403,"duration_ms":6,"user_id":2},"level_name":"INFO","datetime":"2026-10-26T23:17:40+00:00"}
```

Los eventos de negocio (`ticket.created`, `ticket.status_changed`) también salen por este canal con el mismo `trace_id`.

### Métricas (formato Prometheus)

`GET /metrics` (`MetricsController`), con contadores acumulados en Redis desde el mismo middleware:

| Métrica | Tipo | Descripción |
|---|---|---|
| `antibugs_http_requests_total{status_class}` | counter | Peticiones por clase 2xx / 3xx / 4xx / 5xx |
| `antibugs_http_request_duration_ms_sum` | counter | Suma de duraciones (ms) → latencia media = Δsum / Δcount |
| `antibugs_tickets_total{status}` | gauge | Tickets por estado (métrica de negocio) |
| `antibugs_tickets_created_last_hour` | gauge | Tickets creados en la última hora |

## 5.4 Dashboard provisionado

Archivo: `observability/grafana/provisioning/dashboards/json/antibugs-overview.json` · refresco automático cada 10 s.

### Fila *Salud del backend*

| Panel | Tipo | Consulta (PromQL) | Para qué |
|---|---|---|---|
| **Backend** | stat UP / CAÍDO | `up{job="antibugs-backend"}` | ¿Responde el servicio? |
| **Tasa de errores 5xx (5 min)** | stat con umbrales 2 % / **5 %** | `100 · Σrate(5xx[5m]) / Σrate(total[5m])` | El indicador de la alerta principal |
| **Peticiones / min** | stat | `60 · Σrate(total[5m])` | Carga actual |
| **Latencia media** | stat (ms) | `Σrate(duration_sum[5m]) / Σrate(total[5m])` | Rendimiento |
| **Tickets creados (última hora)** | stat | `antibugs_tickets_created_last_hour` | Métrica de negocio en tiempo real |
| **Peticiones por clase de estado HTTP** | serie apilada | `60 · Σ by(status_class) rate(total[2m])` | Distribución 2xx / 4xx / 5xx en el tiempo |
| **Tasa de errores 5xx vs umbral** | serie con línea roja en 5 % | ídem al stat | Ver cuándo se cruza el umbral de alerta |

### Fila *Negocio · tickets*

| Panel | Tipo | Consulta |
|---|---|---|
| **Tickets por estado** | bar gauge horizontal, colores por estado | `antibugs_tickets_total` |
| **Backlog abierto en el tiempo** | serie | `Σ tickets{status=~"OPEN|ASSIGNED|IN_PROGRESS|PENDING_INFO"}` y `tickets{status="RESOLVED"}` |

### Fila *Logs (Loki)*

| Panel | Tipo | Consulta (LogQL) |
|---|---|---|
| **Líneas de log por nivel** | barras apiladas | `sum by (level) (count_over_time({service="backend"}[1m]))` |
| **Errores y advertencias del backend** | logs | `{service="backend", level=~"ERROR\|CRITICAL\|WARNING"}` |
| **Peticiones HTTP** | logs | `{service="backend"} \|= "http.request" \| json \| line_format "{{.context_method}} /{{.context_path}} → {{.context_status}} ({{.context_duration_ms}} ms) …"` |

En cualquier panel de logs, el **`trace_id` es clicable** (campo derivado del datasource Loki): abre *Explore* filtrando todos los logs de esa petición.

## 5.5 Alertas (Prometheus · `observability/alerts.yml`)

| Alerta | Condición | `for` | Severidad |
|---|---|---|---|
| **HighErrorRate** | `Σrate(5xx[5m]) / Σrate(total[5m]) > 0.05` | 5 min | critical |
| **BackendDown** | `up{job="antibugs-backend"} == 0` | 1 min | critical |
| **TicketBacklogGrowing** | `antibugs_tickets_total{status="OPEN"} > 50` | 30 min | warning |

Estado en http://localhost:9090/alerts. En un despliegue real se conectaría **Alertmanager** para enrutar a Slack / email / PagerDuty; en la demo se observa el cambio *inactive → pending → firing* en Prometheus y el panel rojo en Grafana.

## 5.6 Escenario de fallo: cómo se diagnostica

> *"Si los errores HTTP 500 superan el 5 % en 5 minutos, se dispara una alerta. Rastreamos el `trace_id` en Loki para ver si la falla está en la capa de red (Frontend → Backend) o en la lógica del servicio (Backend → DB)."*

1. **Detección** — el stat *Tasa de errores 5xx* pasa a rojo y `HighErrorRate` entra en *firing*.
2. **Acotar** — en *Peticiones por clase de estado* se ve desde cuándo; en *Errores y advertencias* aparecen las excepciones con su `trace_id`.
3. **Correlacionar** — clic en el `trace_id` → se ven todas las líneas de esa petición: la entrada `http.request`, los eventos de negocio y la excepción con `file`/`line`.
4. **Decidir** —
   - Si la excepción es `PDOException` / *connection refused* → falla **Backend → DB** → revisar / reiniciar `mysql`.
   - Si no hay logs del backend para ese `trace_id` pero el frontend recibió 502/504 → falla **Frontend → Backend** (NGINX no alcanza a FrankenPHP) → revisar `backend`.
   - Si el log existe y es un error de lógica → bug de aplicación → el `trace_id` va al ticket interno.

### Reproducir la alerta en una demo

```bash
# Simular 5xx: detener MySQL y generar tráfico autenticado durante unos minutos
docker compose stop mysql
# (hacer peticiones desde la app o con curl) … los 500 aparecen en el dashboard
docker compose start mysql
```

Con la DB detenida, cada petición que toque la base responde 500; en ~5 minutos la tasa supera el 5 % y la alerta pasa a *firing*. Al restaurar MySQL, la tasa cae y la alerta se resuelve sola.

## 5.7 Archivos

```
observability/
├── prometheus.yml                     scrape del backend
├── alerts.yml                         reglas de alerta
├── promtail.yml                       descubrimiento Docker + pipeline JSON (etiqueta `level`)
└── grafana/provisioning/
    ├── datasources/datasources.yml    Prometheus (default) + Loki con trace_id clicable
    └── dashboards/
        ├── dashboards.yml             provider (carpeta "AntiBugs")
        └── json/antibugs-overview.json
```

Los cambios en estos archivos se aplican con `docker compose --profile observability up -d` (Grafana relee los dashboards cada 30 s; para datasources hace falta recrear el contenedor).
