# 1 · Diseño del sistema

> **AntiBugs — Sistema de Gestión de Soporte Técnico**
> Trabajo Práctico Final · Programación de Vanguardia 2026

## 1.1 Problema y solución

La gestión de incidentes técnicos carecía de una *fuente única de verdad*: los pedidos llegaban por canales informales, no había trazabilidad y el tiempo de resolución (*Time To Resolution*) era alto.

AntiBugs centraliza todo el ciclo de vida del soporte en una plataforma web desacoplada (API REST + SPA), con un flujo de estados **auditado**, asignación estructurada de tareas y métricas del servicio.

## 1.2 Usuarios y roles

| Rol | Qué puede hacer |
|---|---|
| **USER** (Usuario / Cliente) | Crear incidentes, ver *sus* tickets, comentar, confirmar el cierre de un ticket resuelto. |
| **TECHNICIAN** (Técnico) | Ver la cola completa ordenada por prioridad, tomar / escalar tickets, cambiar estados con justificación, resolver. |
| **ADMIN** (Administrador) | Todo lo anterior + gestionar usuarios y roles, configurar taxonomía (categorías / prioridades), ver estadísticas y exportar datos. |

## 1.3 Casos de uso

| ID | Caso de uso | Dónde se implementa |
|---|---|---|
| CU-U01 | Crear incidente (con priorización automática) | `POST /tickets` · `TicketService::create()` · `NewTicketPage` |
| CU-U02 | Consultar estado (resumen legible del ciclo de vida) | `GET /tickets`, `GET /tickets/{id}` · campo `summary` del `TicketResource` |
| CU-U03 | Comunicar con soporte (comentarios + adjuntos + notificación) | `POST /tickets/{id}/comments` · `TicketCommentedNotification` |
| CU-T01 | Revisar y asignar / escalar ticket | `PATCH /tickets/{id}/status` con `assignedToUserId` · `GET /technicians` |
| CU-T02 | Actualizar estado (**validación por rol + justificación obligatoria**) | `TicketStateMachine` · `UpdateTicketStatusRequest` · `StatusChangeModal` |
| CU-T03 | Resolver e informar (notifica al usuario pidiendo confirmación) | `TicketService::changeStatus()` → `TicketResolvedNotification` |
| CU-A01 | Gestionar usuarios y roles | `/users` CRUD · `UsersPage` · ver [03-gestion-de-usuarios.md](./03-gestion-de-usuarios.md) |
| CU-A02 | Configurar taxonomía | `/categories`, `/priorities` · `CachedTaxonomyRepository` |
| CU-A03 | Reportes y KPIs | `/reports/*` · `StatsPage` · ver [04-estadisticas.md](./04-estadisticas.md) |

## 1.4 Arquitectura general

```
┌─────────────────────────┐   HTTPS/JSON    ┌──────────────────────────────────────────┐
│  Frontend (React + TS)  │ ──────────────▶ │  Backend (Laravel 13 · API REST /api/v1) │
│  servido por NGINX :80  │ ◀────────────── │  FrankenPHP :8000                        │
│  proxy /api → backend   │   JWT Bearer    │                                          │
└─────────────────────────┘                 │  Controller → Service → Repository       │
                                            └──────┬──────────────────────┬────────────┘
                                                   │                      │
                                         ┌─────────▼────────┐   ┌─────────▼────────┐
                                         │  MySQL 8.4 :3306 │   │  Redis 7 :6379   │
                                         │  datos           │   │  caché · colas · │
                                         │  persistentes    │   │  blacklist JWT   │
                                         └──────────────────┘   └──────────────────┘
```

**Tres capas claras:**

1. **Presentación** — SPA React. Guarda el JWT en `localStorage` y lo envía en cada request. No contiene reglas de negocio: muestra lo que el backend permite (`allowedTransitions`).
2. **Backend / Servicio** — el "cerebro". El *Controller* es sólo un gateway HTTP: valida la entrada (FormRequest), arma un DTO y delega al *Service*, donde viven las reglas de negocio, la transaccionalidad y la máquina de estados.
3. **Persistencia / Caché** — Eloquent sobre MySQL detrás de interfaces de *Repository*. Lecturas frecuentes (taxonomía) se sirven desde Redis y se invalidan al modificarse.

### Flujo de una petición

```
PATCH /api/v1/tickets/7/status
        │
        ▼
AssignTraceId (middleware)      → genera/propaga X-Trace-Id, loguea la request en JSON
        │
        ▼
auth:api (JWT)                  → identifica al usuario; 401 si el token falta/expiró
        │
        ▼
UpdateTicketStatusRequest       → valida: status ∈ enum, justification obligatoria (≥10 chars)…
        │
        ▼
TicketController::updateStatus  → ChangeTicketStatusData::fromArray($validated)   [DTO]
        │
        ▼
TicketService::changeStatus     → DB::transaction {
        │                            lockForUpdate(ticket)
        │                            TicketStateMachine::assertTransition()  ← 403 / 422
        │                            update + TicketStatusHistory::create()
        │                         }
        │                         notify(creator) si RESOLVED
        ▼
TicketResource                  → JSON con summary, allowedTransitions, history…
```

## 1.5 Patrones de diseño aplicados

### State Machine (transaccionalidad)

Archivo: `backend/app/Services/TicketStateMachine.php`

```
OPEN ──► ASSIGNED ──► IN_PROGRESS ──► RESOLVED ──► CLOSED
  │          ▲   ▲        │   ▲          │
  └──────────┼───┘        ▼   │          ▼
             │       PENDING_INFO   (reabrir → IN_PROGRESS)
             └─── (volver a cola)
```

| Desde | Hacia permitido | Quién |
|---|---|---|
| OPEN | ASSIGNED, IN_PROGRESS | TECHNICIAN, ADMIN |
| ASSIGNED | IN_PROGRESS, OPEN | TECHNICIAN, ADMIN |
| IN_PROGRESS | PENDING_INFO, RESOLVED, ASSIGNED | TECHNICIAN, ADMIN |
| PENDING_INFO | IN_PROGRESS | TECHNICIAN, ADMIN |
| RESOLVED | CLOSED, IN_PROGRESS | CLOSED → **creador del ticket** o ADMIN |
| CLOSED | — (estado final) | — |

Reglas adicionales:
- **Toda** transición exige `justification` escrita → queda en `ticket_status_histories`.
- `RESOLVED` exige además `resolution` (solución detallada) y un técnico asignado.
- La transición corre dentro de `DB::transaction` con `SELECT … FOR UPDATE`: si cualquier regla falla, se revierte todo (atomicidad).
- Violación de rol → **403**; transición inválida → **422** (`InvalidStatusTransitionException`).

### Repository + Eloquent ORM

`TicketRepositoryInterface` / `TaxonomyRepositoryInterface` desacoplan el servicio del acceso a datos. El binding está en `AppServiceProvider`. `CachedTaxonomyRepository` agrega la capa Redis de forma transparente (cachea arrays planos y rehidrata modelos, respetando `cache.serializable_classes = false` de Laravel 13).

### DTOs (Data Transfer Objects)

`CreateTicketData`, `ChangeTicketStatusData`, `TicketFilters` — clases `readonly` que son el contrato entre controlador y servicio. Protegen el dominio de cambios en la API externa (p. ej. la API usa `categoryId` en camelCase; el dominio usa `category_id`).

## 1.6 Modelo de datos

```
users ─────────────┐
 id, name, email,  │ created_by      ┌──────────── categories
 password, role    ├────────────────▶│ tickets     │ id, name, is_active
                   │ assigned_to     │ id          │
                   └────────────────▶│ title       │ priorities
                                     │ description │ id, name, level, sla_hours
                                     │ status      │
                                     │ category_id ├─────▶
                                     │ priority_id ├─────▶
                                     │ resolution  │
                                     │ resolved_at │
                                     │ closed_at   │
                                     └──┬───────┬──┘
                                        │       │
                      comments ◀────────┘       └────────▶ ticket_status_histories
                      id, ticket_id, user_id,              id, ticket_id, user_id,
                      body, attachment_path                from_status, to_status,
                                                           assigned_to, justification
```

| Entidad | Rol en el dominio |
|---|---|
| `User` | Implementa `JWTSubject`; el rol es un enum `Role` casteado. |
| `Ticket` | Agregado raíz. `status` es el enum `TicketStatus`. |
| `Comment` | CU-U03; adjunto opcional en disco `public`. |
| `TicketStatusHistory` | Auditoría: cada cambio de estado con autor, justificación y asignación. |
| `Category` / `Priority` | Taxonomía configurable por el admin; `priority.level` ordena la cola del técnico. |

## 1.7 Autenticación y autorización

- **JWT** (`php-open-source-saver/jwt-auth`), guard `api`, TTL 60 min, *refresh* y *logout* (token a blacklist en Redis). Stateless: ningún estado de sesión en el backend.
- El token incluye el claim `role`; el frontend lo usa sólo para la UI; **la autorización real es siempre del backend**:
  - Middleware `role:ADMIN,TECHNICIAN` para rutas por rol.
  - `TicketPolicy` para visibilidad de tickets (un USER sólo ve los suyos).
  - `TicketStateMachine` para transiciones.

## 1.8 API REST (resumen)

| Método | Ruta | Rol |
|---|---|---|
| POST | `/api/v1/auth/login` | público |
| GET / POST | `/api/v1/auth/me` · `/auth/refresh` · `/auth/logout` | autenticado |
| GET | `/api/v1/categories` · `/api/v1/priorities` | autenticado (caché Redis) |
| GET / POST | `/api/v1/tickets` | autenticado |
| GET | `/api/v1/tickets/{id}` | creador o staff |
| PATCH | `/api/v1/tickets/{id}/status` | según State Machine |
| POST | `/api/v1/tickets/{id}/comments` | creador o staff |
| GET | `/api/v1/technicians` | TECHNICIAN / ADMIN |
| CRUD | `/api/v1/users` | ADMIN |
| POST / PATCH | `/api/v1/categories` · `/api/v1/priorities` | ADMIN |
| GET | `/api/v1/reports/kpis` · `/reports/timeline` · `/reports/export` | ADMIN |

Convenciones: JSON camelCase, errores de validación **422** con `errors{}`, no autenticado **401**, sin permiso **403**, inexistente **404**. Todas las respuestas llevan `X-Trace-Id`.

## 1.9 Frontend

- **React 19 + TypeScript + Vite**; `react-router-dom` para rutas; `recharts` (carga diferida) para gráficos.
- Estructura: `api/` (cliente fetch tipado) · `hooks/useAuth` (contexto JWT) · `components/` · `pages/`.
- Rutas protegidas: `RequireAuth` (sesión) y `RequireRole` (rol). Un técnico que escribe `/admin/users` a mano es redirigido al dashboard — y el API respondería 403 igualmente.
- Pantallas: Login · Dashboard (vista usuario / cola técnico agrupada por prioridad) · Nuevo ticket · Detalle (historial, comentarios, modal de **justificación obligatoria**) · Usuarios (admin) · Estadísticas (admin).

## 1.10 Infraestructura y despliegue

`docker-compose.yml` orquesta:

| Servicio | Imagen | Puerto | Función |
|---|---|---|---|
| `frontend` | NGINX + build de React | 80 | Sirve la SPA y hace proxy de `/api` → backend |
| `backend` | FrankenPHP + Laravel | 8000 | API; migra y siembra al arrancar (`entrypoint.sh`) |
| `queue` | misma imagen | — | `queue:work` para notificaciones |
| `mysql` | mysql:8.4 | 3306 | Volumen persistente |
| `redis` | redis:7 | 6379 | Caché, colas, blacklist JWT |
| `prometheus`, `grafana`, `loki`, `promtail` | *profile* `observability` | 9090 / 3000 / 3100 | Métricas y logs |

**CI/CD** (`.github/workflows/ci.yml`): en cada push corre Pint + Pest, lint + build del frontend; en `main` construye y publica imágenes **inmutables** etiquetadas con el SHA del commit en GHCR. *Lo que pasó las pruebas es exactamente lo que se despliega.*

## 1.11 Observabilidad

- **Logs**: canal `json` de Monolog (`JsonFormatter`) a stdout. El middleware `AssignTraceId` agrega `trace_id` a todos los logs de la request (`Log::shareContext`) y lo devuelve en el header `X-Trace-Id`. Promtail los envía a Loki.
- **Métricas**: `GET /metrics` en formato Prometheus — requests por clase de estado, duración acumulada, tickets por estado, creados en la última hora.
- **Alerta** (`observability/alerts.yml`): `HighErrorRate` si los 5xx superan el **5 % en 5 minutos**; `BackendDown`; `TicketBacklogGrowing`.
- Flujo de diagnóstico: alerta en Grafana → buscar el `trace_id` en Loki → determinar si la falla es de red (Frontend → Backend) o de lógica/DB (Backend → MySQL).
