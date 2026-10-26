# 2 · Casos de prueba

## 2.1 Estrategia

| Nivel | Herramienta | Qué verifica | Dónde |
|---|---|---|---|
| **Unitarias** | Pest (PHPUnit) | Reglas de negocio de la *State Machine* en aislamiento, sin base de datos ni HTTP. | `backend/tests/Unit/` |
| **Integración / Feature** | Pest + cliente HTTP de Laravel (`postJson`, `assertStatus`…) | Endpoints completos: middleware, validación, servicio, persistencia y respuesta JSON, contra SQLite en memoria con `RefreshDatabase`. | `backend/tests/Feature/` |
| **Estilo** | Laravel Pint | Formato PSR-12 / preset Laravel. | CI |
| **Frontend** | TypeScript (`tsc -b`) + oxlint | Tipado estricto y lint; el build falla ante errores de tipos. | CI |

Helpers compartidos (`tests/Pest.php`): `actingAsApi($user)` autentica con el guard JWT; `seedTaxonomy()` crea una categoría y las prioridades LOW / HIGH.

**Total: 49 tests · 162 aserciones** (`php artisan test`).

## 2.2 Casos de prueba del documento del TP

Los tres casos exigidos están en `tests/Feature/TicketTest.php`, identificados como *caso 1 / 2 / 3*:

| # | Caso | Precondición | Acción | Resultado esperado | Qué demuestra |
|---|---|---|---|---|---|
| 1 | **Creación exitosa de ticket** | Usuario autenticado, categoría y prioridad existentes | `POST /api/v1/tickets` con título, descripción, `categoryId`, `priorityId` | **201 Created**; JSON con `id`, `status = OPEN`, prioridad aplicada, `createdBy` = usuario; fila en `tickets` y entrada inicial en `ticket_status_histories` | El flujo completo de alta, con auditoría desde el primer momento. |
| 2 | **Transición inválida de estado (rol)** | Ticket `IN_PROGRESS` creado por un usuario común | El mismo usuario envía `PATCH /tickets/{id}/status` con `status = RESOLVED` | **403 Forbidden**; el ticket sigue `IN_PROGRESS` | La State Machine impide estados inconsistentes y protege la integridad: sólo técnicos/admins resuelven. |
| 3 | **Consulta de ticket inexistente** | Técnico autenticado | `GET /api/v1/tickets/9999` | **404 Not Found** con `message` estándar | Manejo uniforme de errores en formato JSON. |

## 2.3 Catálogo completo

### Autenticación — `tests/Feature/AuthTest.php`

| Test | Resultado esperado |
|---|---|
| Credenciales válidas devuelven un JWT | 200 · `accessToken`, `tokenType = Bearer`, `expiresIn`, `user` |
| Credenciales inválidas | 422 con error de validación en `email` |
| El token sirve como Bearer en rutas protegidas | `GET /auth/me` → 200 con el email del usuario |
| Sin token | `GET /tickets` → 401 |

### Ciclo de vida del ticket — `tests/Feature/TicketTest.php`

| Test | Resultado esperado |
|---|---|
| Creación exitosa *(caso 1)* | 201 + historial inicial |
| Usuario común intenta resolver *(caso 2)* | 403, estado intacto |
| Ticket inexistente *(caso 3)* | 404 |
| Técnico toma un ticket OPEN → IN_PROGRESS | 200; se autoasigna; historial con `from/to/justification` |
| Transición inválida OPEN → RESOLVED por técnico | 422 con `from` y `to`; estado intacto |
| Cambio de estado sin justificación | 422, error en `justification` |
| Al resolver se notifica al creador | `Notification::assertSentTo(creator, TicketResolvedNotification)` |
| El creador puede cerrar un ticket RESOLVED | 200, `status = CLOSED` |
| Un usuario común sólo ve sus tickets | `GET /tickets` devuelve sólo los propios |
| Un usuario común no ve tickets ajenos | `GET /tickets/{ajeno}` → 403 |
| La cola del técnico se ordena por prioridad | Primero HIGH, luego LOW |
| Un usuario comenta su ticket | 201, autor correcto, fila en `comments` |
| Técnico en endpoint de admin | `GET /reports/kpis` → 403 |
| Staff lista técnicos para escalar; usuario común no | 200 con técnicos + admins / 403 |
| KPIs para admin | 200 con `total`, `open`, `avgResolutionHours`, desgloses |

### Máquina de estados (unitarias) — `tests/Unit/TicketStateMachineTest.php`

| Test | Datos |
|---|---|
| Transiciones válidas (*dataset*) | OPEN→ASSIGNED, OPEN→IN_PROGRESS, IN_PROGRESS→PENDING_INFO, PENDING_INFO→IN_PROGRESS, IN_PROGRESS→RESOLVED, RESOLVED→CLOSED |
| Transiciones inválidas (*dataset*) | OPEN→RESOLVED, OPEN→CLOSED, CLOSED→OPEN, PENDING_INFO→RESOLVED |
| Técnico fuerza transición inválida | lanza `InvalidStatusTransitionException` |
| Usuario común intenta resolver | lanza `AuthorizationException` |
| Resolver sin técnico asignado | lanza `InvalidStatusTransitionException` ("sin técnico asignado") |
| Sólo el creador confirma el cierre | otro USER → `AuthorizationException` |
| CLOSED es estado final | `allowedTransitions(CLOSED)` está vacío |

### Gestión de usuarios — `tests/Feature/UserManagementTest.php`

| Test | Resultado esperado |
|---|---|
| Admin lista, crea, actualiza y elimina usuarios | 200 paginado con `meta` · filtro `?role=` · 201 · 200 con rol cambiado · 204 y fila eliminada |
| Email duplicado y contraseña débil | 422 con errores en `email` y `password` |
| Admin no puede eliminarse ni quitarse el rol ADMIN | 422 en ambos; puede cambiarse el nombre |
| Técnicos y usuarios no acceden a `/users` | 403 |

### Taxonomía y caché — `tests/Feature/TaxonomyTest.php`

| Test | Resultado esperado |
|---|---|
| Usuario común lista categorías activas y prioridades | 200; excluye categorías inactivas; prioridades ordenadas por nivel desc |
| **Regresión**: la caché sólo contiene arrays planos | el payload cacheado es deserializable con `allowed_classes => false` |
| Las filas cacheadas se rehidratan como modelos | instancias de `Category` / `Priority`; `defaultPriority()` = LOW |
| Crear una categoría invalida la caché | segunda lectura devuelve 2 categorías |

> La regresión documenta un bug real: Laravel 13 bloquea la deserialización de objetos desde la caché; cachear modelos Eloquent en Redis provocaba un 500 y el combo de categorías vacío.

### Reportes — `tests/Feature/ReportTest.php`

| Test | Resultado esperado |
|---|---|
| Serie diaria de creados / resueltos / backlog | 7 puntos; backlog inicial correcto; `summary.avgResolutionHours = 48` para un ticket resuelto en 2 días |
| Rango acotado | `days=0` → 1; `days=9999` → 365 |
| Desglose por técnico | sólo técnicos con actividad; `resolved` y `open` correctos |
| No admin en `/reports/timeline` | 403 |

### Comando de datos históricos — `tests/Feature/SeedDemoHistoryTest.php`

| Test | Resultado esperado |
|---|---|
| Genera tickets con fechas pasadas y coherentes | `created_at` respetado; `resolved_at > created_at`; `closed_at > resolved_at`; con historial |
| `--fresh` reemplaza la historia previa | la cantidad total de tickets no se duplica |
| Sin datos base | el comando falla con mensaje claro |

## 2.4 Cómo ejecutar

```bash
cd backend
php artisan test                      # toda la suite
php artisan test --compact            # salida resumida
php artisan test tests/Unit           # sólo unitarias
php artisan test --filter "caso 2"    # un test puntual
vendor/bin/pint --test                # estilo de código

cd ../frontend
npm run lint && npm run build         # lint + tipado + build
```

En CI (`.github/workflows/ci.yml`) todo esto corre automáticamente en cada push y pull request; la publicación de imágenes Docker sólo ocurre si las pruebas pasan.
