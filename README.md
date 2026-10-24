# 🐛 AntiBugs — Sistema de Gestión de Soporte Técnico

> *Controla la verdadera plaga en tu sistema.*

Plataforma para centralizar el ciclo de vida del soporte técnico con roles (Usuario / Técnico / Administrador), máquina de estados auditada y notificaciones. Trabajo Práctico Final — Programación de Vanguardia 2026.

## Stack

| Capa | Tecnología | Puerto |
|---|---|---|
| Frontend | React 19 + TypeScript + Vite, servido por NGINX | 80 |
| Backend | Laravel 13 (PHP 8.3) · API REST · JWT · FrankenPHP | 8000 |
| Base de datos | MySQL 8.4 | 3306 |
| Caché / colas / tokens | Redis 7 | 6379 |
| Observabilidad *(opcional)* | Prometheus · Grafana · Loki · Promtail | 9090 · 3000 · 3100 |

## Inicio rápido con Docker

```bash
cp .env.example .env          # claves de desarrollo incluidas
docker compose up -d --build  # frontend + backend + queue + mysql + redis
```

- App: http://localhost
- API: http://localhost:8000/api/v1 · health: http://localhost:8000/up · métricas: http://localhost:8000/metrics

El backend ejecuta las migraciones automáticamente y, con `SEED_DATABASE=true`, carga datos demo:

| Rol | Email | Contraseña |
|---|---|---|
| Usuario | usuario@antibugs.local | password |
| Técnico | tecnico@antibugs.local | password |
| Admin | admin@antibugs.local | password |

Con observabilidad:

```bash
docker compose --profile observability up -d
```

Grafana: http://localhost:3000 (admin / admin) con Prometheus y Loki ya provisionados.

## Desarrollo local (sin Docker)

```bash
# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate && php artisan jwt:secret
php artisan migrate --seed          # usa SQLite por defecto
php artisan serve                   # http://localhost:8000

# Frontend (en otra terminal)
cd frontend
npm install
npm run dev                         # http://localhost:5173 (proxy /api → :8000)
```

## Tests

```bash
cd backend
php artisan test            # Pest: unitarios (State Machine) + feature (endpoints)
vendor/bin/pint --test      # estilo de código
```

Casos de prueba del documento: creación de ticket → **201**, usuario común intenta resolver → **403**, ticket inexistente → **404**. Además: transición inválida → **422**, justificación obligatoria, notificación al resolver, visibilidad por rol, KPIs.

## Arquitectura del backend

```
Request → Controller (valida, FormRequest) → DTO → Service (reglas, transacción, State Machine)
                                                        ↓
                                              Repository (Eloquent / Redis cache)
```

| Pieza | Ubicación |
|---|---|
| Máquina de estados | `app/Services/TicketStateMachine.php` |
| Lógica de negocio | `app/Services/TicketService.php` |
| DTOs | `app/DTOs/` |
| Repositorios (interfaz + Eloquent) | `app/Repositories/` |
| Controladores API v1 | `app/Http/Controllers/Api/V1/` |
| Logs JSON + trace_id | `config/logging.php` (canal `json`), `app/Http/Middleware/AssignTraceId.php` |
| Métricas Prometheus | `app/Http/Controllers/MetricsController.php` → `GET /metrics` |

### Flujo de estados

```
OPEN ──► ASSIGNED ──► IN_PROGRESS ──► RESOLVED ──► CLOSED
  └──────────────────────┘  ▲   │          │
                            │   └► PENDING_INFO
                            └──────────┘
```

- Sólo **TECHNICIAN / ADMIN** mueven el ticket entre estados de trabajo.
- Sólo el **creador** (o ADMIN) confirma `RESOLVED → CLOSED`.
- Toda transición exige `justification`; `RESOLVED` exige además `resolution`.
- No se puede resolver sin técnico asignado. La transición es atómica (`DB::transaction` + `lockForUpdate`).

## Endpoints principales

| Método | Ruta | Rol |
|---|---|---|
| POST | `/api/v1/auth/login` | público |
| GET | `/api/v1/auth/me` · POST `/auth/refresh` · POST `/auth/logout` | autenticado |
| GET | `/api/v1/categories` · `/api/v1/priorities` | autenticado (cacheado en Redis) |
| GET / POST | `/api/v1/tickets` | autenticado |
| GET | `/api/v1/tickets/{id}` | creador o staff |
| PATCH | `/api/v1/tickets/{id}/status` | según State Machine |
| POST | `/api/v1/tickets/{id}/comments` | creador o staff |
| GET | `/api/v1/technicians` | TECHNICIAN / ADMIN (para escalar) |
| CRUD | `/api/v1/users` · POST `/categories` · POST `/priorities` | ADMIN |
| GET | `/api/v1/reports/kpis` · `/api/v1/reports/export` (CSV) | ADMIN |

Ejemplo de cambio de estado:

```http
PATCH /api/v1/tickets/1/status
Authorization: Bearer <jwt>

{ "status": "IN_PROGRESS", "assignedToUserId": 2, "justification": "Tomo el caso, corresponde a mi especialidad." }
```

## CI/CD

`.github/workflows/ci.yml`: en cada push/PR corre Pint + Pest (backend) y lint + build (frontend). En `main`, construye y publica imágenes Docker **inmutables** etiquetadas con el SHA del commit en GHCR.
