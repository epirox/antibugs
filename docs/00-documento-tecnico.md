# AntiBugs — Sistema de Gestión de Soporte Técnico

> *Controla la verdadera plaga en tu sistema.*

**Universidad de la Ciudad de Buenos Aires · Programación de Vanguardia · 2.º cuatrimestre 2026**
Trabajo Final Grupal — Proyecto 8: *Sistema de Gestión de Soporte Técnico*
Repositorio: https://github.com/epirox/antibugs

> **Cómo usar este archivo:** es el texto del documento técnico listo para pegar en el Google Doc. Las marcas `[FIGURA n]` indican dónde insertar cada PNG de `docs/diagramas/`. Las secciones siguen el orden y la numeración de la rúbrica.

---

## 1. Descripción del sistema

### 1.1 Problema a resolver

La gestión de incidentes técnicos en la organización carece de una *fuente única de verdad*. Los pedidos de soporte llegan por canales informales (mensajería, correo, de palabra), lo que produce tres efectos concretos: **latencia** en el tiempo de resolución (*Time To Resolution*), **silos de información** (cada técnico conoce sólo sus casos) y **falta de trazabilidad** (no es posible reconstruir quién hizo qué, cuándo y por qué).

### 1.2 Solución propuesta

AntiBugs es una plataforma web desacoplada (API REST + aplicación de página única) que centraliza el ciclo de vida completo de un incidente: creación, asignación, trabajo, resolución y cierre. Cada cambio de estado es **auditado** (quién, cuándo, de qué estado a cuál y con qué justificación), la cola de trabajo se ordena por **prioridad**, y el administrador dispone de **métricas** del servicio para tomar decisiones.

**Objetivos medibles:** reducir el tiempo promedio de resolución; garantizar que el 100 % de los cambios de estado queden registrados con justificación; que todo usuario pueda conocer el estado de su pedido sin preguntar.

### 1.3 Usuarios y roles

| Rol | Objetivo | Qué puede hacer |
|---|---|---|
| **Usuario / Cliente** | Reportar y seguir sus incidentes | Crear tickets, ver *sus* tickets y su historial, comentar y adjuntar, confirmar el cierre cuando el técnico resolvió. |
| **Técnico** | Resolver incidentes con eficiencia | Ver la cola completa ordenada por prioridad, tomar o escalar tickets, cambiar estados con justificación, registrar la solución. |
| **Administrador** | Controlar y optimizar el servicio | Todo lo anterior, más: gestionar usuarios y roles, configurar categorías y prioridades, consultar estadísticas y exportar datos. |

### 1.4 Alcance funcional seleccionado

| ID | Caso de uso | Requisito clave |
|---|---|---|
| CU-U01 | Crear incidente | Valida la categoría y **prioriza automáticamente** según palabras clave de la descripción; el admin puede ajustar. |
| CU-U02 | Consultar estado | Resumen legible del ciclo de vida (*"Asignado a Juan Pérez – En proceso"*) e historial completo. |
| CU-U03 | Comunicar con soporte | Comentarios y adjuntos sin modificar el ticket; notifica a los responsables. |
| CU-T01 | Revisar y asignar / escalar | Mecanismo de escalamiento a un colega; la razón queda en el historial. |
| CU-T02 | Actualizar estado | **Crítico:** sólo ciertos roles y transiciones son válidos; toda transición exige justificación escrita. |
| CU-T03 | Resolver e informar | Registra la solución y notifica al usuario pidiendo confirmación de cierre. |
| CU-A01 | Gestionar usuarios y roles | Control granular de acceso por rol a cada módulo del API. |
| CU-A02 | Configurar taxonomía | Categorías y prioridades definen las reglas para todos los tickets. |
| CU-A03 | Reportes y KPIs | Tiempo promedio de resolución, volumen por categoría, historia de resolución, exportación CSV. |

**Fuera de alcance (decisión consciente):** cálculo automático de vencimiento de SLA, base de conocimiento, integración con proveedores de correo o chat reales, aplicación móvil nativa y facturación. Estas funciones se mencionan como evolución posible pero no forman parte del diseño evaluado.

**Estado de la implementación:** el sistema está desarrollado de punta a punta como prueba de concepto funcional (backend, frontend, contenedores, pipeline y monitoreo). Las instrucciones de ejecución están en el anexo A.

---

## 2. Arquitectura y patrones de diseño

### 2.1 Visión general

El sistema se organiza en **tres capas** con responsabilidades disjuntas, comunicadas únicamente mediante contratos explícitos (JSON sobre HTTP entre presentación y backend; interfaces de repositorio entre backend y persistencia).

`[FIGURA 1 — 01-arquitectura.png: Arquitectura por capas y componentes]`

| Capa | Tecnología | Responsabilidad | Lo que **no** hace |
|---|---|---|---|
| **Presentación** | React 19 + TypeScript, servido por NGINX | Mostrar información, capturar entradas, enviar peticiones autenticadas con JWT. | No contiene reglas de negocio: renderiza las transiciones que el backend le indica como permitidas (`allowedTransitions`). |
| **Backend / Servicio** | Laravel 13 (PHP 8.3) sobre FrankenPHP | Validar entradas, aplicar reglas de negocio, garantizar atomicidad, autorizar, emitir notificaciones y métricas. | No conoce el formato de pantalla ni el motor de base de datos concreto. |
| **Persistencia y caché** | MySQL 8.4 · Redis 7 | Almacenar datos transaccionales (MySQL) y servir lecturas frecuentes, colas y la lista de revocación de tokens (Redis). | No contiene lógica (sin procedimientos almacenados ni *triggers*). |

### 2.2 Flujo de una petición

El recorrido de la operación más compleja del sistema —el cambio de estado de un ticket— ilustra cómo cada componente asume una única responsabilidad:

`[FIGURA 2 — 02-secuencia-cambio-estado.png: Secuencia PATCH /tickets/{id}/status]`

1. **Middleware** `AssignTraceId` genera (o propaga) un identificador de traza y lo inyecta en todos los logs de la petición; `auth:api` valida el JWT y resuelve el usuario y su rol.
2. **FormRequest** `UpdateTicketStatusRequest` valida la forma de los datos: el estado pertenece al enumerado, la justificación es obligatoria y tiene al menos 10 caracteres.
3. **Controller** actúa como *gateway* HTTP: construye un DTO con los datos validados y delega al servicio. No contiene condicionales de negocio.
4. **Service** `TicketService::changeStatus()` abre una transacción, bloquea la fila (`SELECT … FOR UPDATE`), consulta a la máquina de estados, persiste el cambio y el registro de auditoría, y confirma. Si cualquier regla falla, toda la operación se revierte.
5. **State Machine** `TicketStateMachine` decide si la transición es válida para ese estado origen y ese rol. Devuelve 403 (rol no autorizado) o 422 (transición inválida).
6. **Repository** encapsula el acceso a datos; el servicio depende de la interfaz, no de Eloquent.
7. **Resource** serializa la respuesta con el resumen legible, las transiciones permitidas y el historial.

### 2.3 Patrones de diseño aplicados

#### State Machine (patrón Estado + transaccionalidad)

El ciclo de vida del ticket se modela como un autómata finito de seis estados. La tabla de transiciones y la de roles autorizados viven en una única clase (`TicketStateMachine`), lo que evita que las reglas se dispersen en controladores o vistas.

`[FIGURA 3 — 03-maquina-de-estados.png: Máquina de estados del ticket]`

| Desde | Hacia | Quién |
|---|---|---|
| OPEN | ASSIGNED · IN_PROGRESS | Técnico, Admin |
| ASSIGNED | IN_PROGRESS · OPEN | Técnico, Admin |
| IN_PROGRESS | PENDING_INFO · RESOLVED · ASSIGNED | Técnico, Admin |
| PENDING_INFO | IN_PROGRESS | Técnico, Admin |
| RESOLVED | CLOSED · IN_PROGRESS | CLOSED: **creador del ticket** o Admin |
| CLOSED | — | Estado final |

Reglas transversales: toda transición exige justificación; `RESOLVED` exige además la descripción de la solución y un técnico asignado. La transición se ejecuta dentro de `DB::transaction` con bloqueo pesimista: dos técnicos que intenten tomar el mismo ticket simultáneamente no pueden producir un estado inconsistente.

**Por qué este patrón y no `if` en el controlador:** las reglas son datos (dos tablas), se prueban de forma unitaria sin base de datos ni HTTP, y agregar un estado o cambiar quién puede ejecutar una transición no toca ningún otro componente.

#### Repository + ORM

`TicketRepositoryInterface` y `TaxonomyRepositoryInterface` abstraen el acceso a datos; la implementación concreta usa Eloquent. El contenedor de inyección de dependencias de Laravel resuelve la interfaz a la implementación en `AppServiceProvider`.

**Por qué, si Eloquent ya es una abstracción:** (1) los servicios se prueban con dobles del repositorio; (2) la implementación `CachedTaxonomyRepository` agrega la capa Redis de forma transparente, sin que el servicio sepa que existe una caché; (3) el servicio nunca construye consultas, lo que mantiene la lógica de negocio libre de SQL.

#### Data Transfer Objects

`CreateTicketData`, `ChangeTicketStatusData` y `TicketFilters` son clases inmutables (`readonly`) que constituyen el contrato entre el controlador y el servicio. Protegen el dominio de los cambios en la API externa: la API expone `categoryId` (camelCase, convención JSON), el dominio usa `category_id`; si mañana la API cambia, sólo cambia la construcción del DTO.

#### Patrones complementarios

- **Policy** (`TicketPolicy`): autorización a nivel de recurso — un usuario sólo ve sus propios tickets.
- **Middleware de rol** (`role:ADMIN,TECHNICIAN`): autorización a nivel de ruta.
- **Observer/Notification**: las notificaciones se despachan a una cola y las procesa un *worker* independiente, de modo que el envío de correo nunca demora la respuesta HTTP.

### 2.4 Decisiones arquitectónicas y alternativas descartadas

| Decisión | Alternativa descartada | Justificación |
|---|---|---|
| **JWT stateless** en lugar de sesiones | Sesiones con cookie en el servidor | Permite escalar el backend horizontalmente sin sesión compartida; el token lleva el rol como *claim*. La revocación (logout) se resuelve con una *blacklist* en Redis con el TTL del token. |
| **Redis** para caché, colas y blacklist | Caché en base de datos / colas síncronas | Las lecturas de taxonomía (categorías, prioridades) ocurren en cada carga de pantalla; servirlas desde memoria evita consultas repetidas. Las colas desacoplan las notificaciones de la petición. |
| **FrankenPHP** como servidor del backend | PHP-FPM + NGINX dentro del contenedor | Un solo proceso y una sola imagen; servidor HTTP moderno con *healthcheck* nativo. Menos piezas que orquestar. |
| **NGINX como proxy** del frontend hacia `/api` | CORS con dos orígenes | Mismo origen para SPA y API: no hay CORS, el token nunca viaja a otro dominio y la configuración es idéntica en desarrollo y producción. |
| **Reglas sólo en el backend** | Validar también permisos en el frontend | El frontend consume `allowedTransitions` y oculta lo que no corresponde, pero la decisión final es siempre del servidor; una URL forzada recibe 403. |

### 2.5 Modelo de datos

`[FIGURA 4 — 04-modelo-de-datos.png: Modelo entidad-relación]`

| Entidad | Rol en el dominio |
|---|---|
| `users` | Identidad y rol (`USER`, `TECHNICIAN`, `ADMIN`). |
| `tickets` | Agregado raíz: título, descripción, estado, categoría, prioridad, creador, técnico asignado, solución, fechas de resolución y cierre. |
| `ticket_status_histories` | Auditoría: cada transición con estado origen/destino, actor, asignado y justificación. |
| `comments` | Comunicación usuario ↔ soporte, con adjunto opcional. |
| `categories` · `priorities` | Taxonomía configurable; `priorities.level` ordena la cola del técnico. |
| `notifications` | Notificaciones persistidas por usuario. |

---

## 3. Frontend

### 3.1 Tecnología y justificación

**React 19 + TypeScript + Vite.** React permite componer la interfaz a partir de componentes reutilizables (insignias de estado, modal de justificación, tablas de tickets). TypeScript tipa el contrato con el backend: los tipos `Ticket`, `User`, `TicketStatus` reflejan exactamente el JSON del API, de modo que un cambio de contrato se detecta en compilación y no en producción. Vite ofrece recarga instantánea en desarrollo y un *build* estático optimizado que NGINX sirve sin necesidad de Node en producción.

### 3.2 Estructura y navegación

```
src/
├── api/        cliente HTTP tipado (fetch + Bearer) y tipos del dominio
├── hooks/      useAuth: contexto de sesión (login, logout, usuario actual)
├── components/ Layout, Badges, StatusChangeModal
└── pages/      Login · Dashboard · NewTicket · TicketDetail · Users · Stats
```

| Ruta | Pantalla | Rol |
|---|---|---|
| `/login` | Formulario de acceso; guarda el JWT en `localStorage`. | público |
| `/` | **Dashboard.** Usuario: tarjeta de sus tickets + botón *Nuevo ticket*. Técnico/Admin: cola agrupada por prioridad con filtros por estado, categoría y *asignados a mí*. | autenticado |
| `/tickets/new` | Alta de incidente; la prioridad puede dejarse en *Automática*. | autenticado |
| `/tickets/:id` | **Detalle:** resumen, descripción, solución, botones de transición (sólo las permitidas), historial y comentarios. | creador o staff |
| `/admin/users` | Gestión de usuarios y roles. | Admin |
| `/admin/stats` | KPIs y gráficos (historia de resolución, por estado, categoría, prioridad y técnico). | Admin |

Las rutas están protegidas por `RequireAuth` (sesión) y `RequireRole` (rol). Un técnico que escriba `/admin/users` es redirigido al dashboard; el API, en cualquier caso, respondería 403.

### 3.3 Interacción con el backend

- Todas las llamadas pasan por un único cliente (`api/client.ts`) que agrega `Authorization: Bearer`, maneja 401 (limpia la sesión) y convierte los 422 en errores por campo que cada formulario muestra bajo el control correspondiente.
- **Impacto inmediato de las acciones:** al pulsar una transición en el detalle se abre la ventana de **justificación obligatoria** (y de solución, si se resuelve); al confirmar, la respuesta del backend reemplaza el ticket en pantalla, con el nuevo estado, el técnico asignado y la entrada agregada al historial.
- El frontend nunca calcula qué transiciones son válidas: pinta `allowedTransitions` que llegan en cada ticket.

`[Insertar 2–3 capturas: dashboard del técnico, detalle con la modal de justificación, estadísticas]`

---

## 4. Backend, POO y APIs

### 4.1 Organización lógica

```
app/
├── Enums/            Role, TicketStatus
├── DTOs/             CreateTicketData, ChangeTicketStatusData, TicketFilters
├── Http/
│   ├── Controllers/  Auth, Ticket, Comment, User, Taxonomy, Report, Metrics
│   ├── Requests/     validación de entrada (FormRequest)
│   ├── Resources/    serialización JSON (TicketResource, UserResource…)
│   └── Middleware/   AssignTraceId, EnsureUserHasRole
├── Services/         TicketService, TicketStateMachine
├── Repositories/     Contracts (interfaces) + Eloquent (implementaciones)
├── Policies/         TicketPolicy
├── Models/           User, Ticket, Comment, Category, Priority, TicketStatusHistory
└── Notifications/    TicketResolvedNotification, TicketCommentedNotification
```

| Clase | Responsabilidad única |
|---|---|
| `TicketController` | Recibir la petición, delegar validación al FormRequest, construir el DTO, invocar al servicio, devolver el Resource. |
| `TicketService` | Reglas de negocio: creación con priorización automática, cambio de estado transaccional, comentarios con notificación. |
| `TicketStateMachine` | Tabla de transiciones válidas y de roles autorizados; lanza excepciones tipadas. |
| `EloquentTicketRepository` | Consultas: paginación con visibilidad por rol, orden por prioridad, bloqueo pesimista. |
| `CachedTaxonomyRepository` | Categorías y prioridades con caché en Redis e invalidación al modificarse. |
| `TicketPolicy` | ¿Puede este usuario ver / comentar este ticket? |
| `TicketResource` | Forma de salida: `summary`, `allowedTransitions`, relaciones cargadas. |

Los modelos usan enumerados de PHP (`Role`, `TicketStatus`) casteados por Eloquent, lo que hace imposible persistir un estado o rol fuera del dominio.

### 4.2 Endpoints

Prefijo `/api/v1`. Respuestas JSON en camelCase; todas incluyen el header `X-Trace-Id`.

| Método | Ruta | Descripción | Acceso |
|---|---|---|---|
| POST | `/auth/login` | Autentica y devuelve el JWT | público |
| GET | `/auth/me` · POST `/auth/refresh` · POST `/auth/logout` | Sesión | autenticado |
| GET | `/categories` · `/priorities` | Taxonomía (caché Redis) | autenticado |
| GET | `/tickets?status=&categoryId=&mine=1` | Listado paginado; el usuario común sólo ve los suyos | autenticado |
| POST | `/tickets` | Crear ticket (CU-U01) | autenticado |
| GET | `/tickets/{id}` | Detalle con historial y comentarios | creador o staff |
| PATCH | `/tickets/{id}/status` | Cambiar estado (CU-T01/02/03) | según State Machine |
| POST | `/tickets/{id}/comments` | Comentar / adjuntar (CU-U03) | creador o staff |
| GET | `/technicians` | Colegas a quienes escalar | Técnico, Admin |
| GET/POST/PATCH/DELETE | `/users` | Gestión de usuarios (CU-A01) | Admin |
| POST/PATCH | `/categories` · `/priorities` | Taxonomía (CU-A02) | Admin |
| GET | `/reports/kpis` · `/reports/timeline?days=30` · `/reports/export` | Reportes (CU-A03) | Admin |

Códigos: **201** creado · **200** OK · **204** sin contenido · **401** no autenticado · **403** sin permiso · **404** inexistente · **422** validación o transición inválida.

### 4.3 Ejemplos representativos

**Autenticación**

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "tecnico@antibugs.local", "password": "********" }
```
```http
HTTP/1.1 200 OK
{
  "accessToken": "eyJhbGciOiJIUzI1NiIs…",
  "tokenType": "Bearer",
  "expiresIn": 3600,
  "user": { "id": 2, "name": "Juan Pérez", "email": "tecnico@antibugs.local", "role": "TECHNICIAN" }
}
```

**Crear ticket** — la prioridad se infiere de la descripción (palabra *urgente* → CRITICAL)

```http
POST /api/v1/tickets
Authorization: Bearer <jwt>

{ "title": "Fallo en impresora", "description": "No imprime en red, es urgente", "categoryId": 1 }
```
```http
HTTP/1.1 201 Created
{
  "data": {
    "id": 7, "title": "Fallo en impresora", "status": "OPEN", "statusLabel": "Abierto",
    "summary": "Sin técnico asignado - Abierto",
    "allowedTransitions": ["ASSIGNED", "IN_PROGRESS"],
    "category": { "id": 1, "name": "Hardware" },
    "priority": { "id": 4, "name": "CRITICAL", "level": 4 },
    "createdBy": { "id": 3, "name": "María Cliente", "role": "USER" },
    "assignedTo": null, "createdAt": "2026-10-26T18:07:08.000000Z"
  }
}
```

**Cambiar estado** — el técnico toma el caso

```http
PATCH /api/v1/tickets/7/status
Authorization: Bearer <jwt-técnico>

{ "status": "IN_PROGRESS", "justification": "Tomo el caso, corresponde a mi especialidad." }
```
```http
HTTP/1.1 200 OK
{
  "data": {
    "id": 7, "status": "IN_PROGRESS", "summary": "Asignado a Juan Pérez - En proceso",
    "allowedTransitions": ["PENDING_INFO", "RESOLVED", "ASSIGNED"],
    "assignedTo": { "id": 2, "name": "Juan Pérez", "role": "TECHNICIAN" },
    "history": [
      { "from": "OPEN", "to": "IN_PROGRESS", "actor": { "name": "Juan Pérez" },
        "justification": "Tomo el caso, corresponde a mi especialidad.", "createdAt": "…" },
      { "from": null, "to": "OPEN", "actor": { "name": "María Cliente" },
        "justification": "Ticket creado por el usuario.", "createdAt": "…" }
    ]
  }
}
```

**Errores** — la máquina de estados protege la integridad

```http
PATCH /api/v1/tickets/7/status          (usuario común intenta resolver)
{ "status": "RESOLVED", "justification": "Ya lo arreglé yo." }

HTTP/1.1 403 Forbidden
{ "message": "El rol USER no puede cambiar un ticket al estado RESOLVED." }
```
```http
PATCH /api/v1/tickets/7/status          (técnico intenta saltar OPEN → RESOLVED)
{ "status": "RESOLVED", "justification": "…", "resolution": "…" }

HTTP/1.1 422 Unprocessable Content
{ "message": "La transición OPEN → RESOLVED no está permitida.",
  "errors": { "status": ["La transición OPEN → RESOLVED no está permitida."] },
  "from": "OPEN", "to": "RESOLVED" }
```
```http
PATCH /api/v1/tickets/7/status          (sin justificación)
{ "status": "IN_PROGRESS" }

HTTP/1.1 422 Unprocessable Content
{ "message": "Debe indicar una justificación para el cambio de estado.",
  "errors": { "justification": ["Debe indicar una justificación para el cambio de estado."] } }
```

**Justificación técnica del endpoint de estado:** no es un simple *update*. Implica una transacción que valida reglas concurrentes — el rol del actor, el estado actual del ticket, la presencia de técnico asignado y de solución — y persiste dos escrituras (ticket + historial) de forma atómica. El patrón Service Layer concentra esa lógica en un punto, testeable de forma unitaria e independiente del transporte HTTP.

---

## 5. Testing

### 5.1 Estrategia

| Nivel | Herramienta | Qué se verifica |
|---|---|---|
| **Unitarias** | Pest (PHPUnit) | Reglas de la máquina de estados en aislamiento: sin base de datos, sin HTTP. |
| **Integración / funcionales** | Pest + cliente HTTP de Laravel | Endpoints completos contra SQLite en memoria: middleware, validación, servicio, persistencia y JSON de respuesta. |
| **Estáticas** | Laravel Pint · TypeScript (`tsc`) · oxlint | Estilo del backend; tipado y lint del frontend. |

Pest se elige por ser el *runner* nativo del ecosistema Laravel: aprovecha `RefreshDatabase`, *factories*, `actingAs` con el guard JWT y `Notification::fake()`. Las pruebas se ejecutan en cada *push* dentro del pipeline (sección 6). El estado actual es de **49 pruebas y 162 aserciones**, todas en verde.

### 5.2 Casos de prueba

| # | Caso | Acción | Resultado esperado | Qué demuestra |
|---|---|---|---|---|
| 1 | **Creación exitosa de ticket** | Usuario autenticado envía `POST /tickets` con datos válidos | **201 Created** con el `id`, `status = OPEN`, prioridad aplicada y primera entrada del historial en base de datos | Flujo de alta completo con auditoría desde el origen. |
| 2 | **Transición inválida de estado** | Usuario común intenta `PATCH …/status` a `RESOLVED` sobre un ticket en proceso | **403 Forbidden**; el ticket conserva su estado | La máquina de estados previene estados inconsistentes y protege la integridad: sólo técnicos/admins resuelven. |
| 3 | **Consulta de ticket inexistente** | `GET /tickets/9999` | **404 Not Found** con mensaje de error estándar | Manejo uniforme de errores en JSON. |
| 4 | Transición inválida por técnico | `OPEN → RESOLVED` | **422** con `from` y `to` | Las reglas aplican también al staff. |
| 5 | Justificación obligatoria | `PATCH …/status` sin `justification` | **422**, error en el campo | Requisito crítico del CU-T02. |
| 6 | Notificación al resolver | Técnico pasa a `RESOLVED` | Se despacha `TicketResolvedNotification` al creador | CU-T03. |
| 7 | Visibilidad por rol | Usuario lista tickets / abre uno ajeno | Sólo los propios / **403** | Policy. |
| 8 | Caché de taxonomía (regresión) | Leer categorías dos veces | El contenido cacheado es un array plano, deserializable de forma segura | Documenta un bug real detectado en Docker: Laravel 13 bloquea deserializar objetos desde la caché. |

Los casos 1–3 están implementados con esos nombres (`caso 1`, `caso 2`, `caso 3`) en `tests/Feature/TicketTest.php`; la máquina de estados tiene además 10 pruebas unitarias parametrizadas con todas las transiciones válidas e inválidas.

---

## 6. Docker, DevOps y despliegue

### 6.1 Servicios y comunicación

`[FIGURA 5 — 05-docker-compose.png: Contenedores Docker Compose]`

| Servicio | Imagen | Puerto | Responsabilidad |
|---|---|---|---|
| **frontend** | NGINX 1.27 + *build* estático de React | 80 | Sirve la SPA y hace *proxy* de `/api` y `/storage` hacia el backend. React se precompila a HTML/JS estático: no hay Node en producción. |
| **backend** | FrankenPHP (PHP 8.3) + Laravel 13 | 8000 | API REST. Al arrancar espera a MySQL y Redis, ejecuta migraciones y, opcionalmente, datos iniciales. |
| **queue** | misma imagen que backend | — | `php artisan queue:work`: procesa notificaciones fuera de la petición HTTP. |
| **mysql** | MySQL 8.4 | 3306 | Datos persistentes, volumen `mysql_data`. |
| **redis** | Redis 7 | 6379 | Caché de taxonomía, colas y *blacklist* de JWT. Volumen `redis_data`. |
| *prometheus · loki · promtail · grafana* | perfil `observability` | 9090 · 3100 · — · 3000 | Monitoreo (sección 7). Se activan con `--profile observability`. |

**Orquestación y orden de arranque.** Cada servicio declara un `healthcheck` y `depends_on` con `condition: service_healthy`: MySQL y Redis deben responder antes de que arranque el backend; el backend debe responder en `/up` antes de que arranquen el frontend y el *worker*. El *entrypoint* del backend espera activamente los puertos, ejecuta `migrate --force` y cachea configuración y rutas. Así el `docker compose up -d` es idempotente y reproducible.

**Imágenes.** Ambas se construyen en varias etapas (*multi-stage*): las dependencias (Composer, npm) se resuelven en una etapa descartable y la imagen final sólo contiene el código y el *runtime*. La configuración se inyecta por variables de entorno (`.env`); ningún secreto está en la imagen.

### 6.2 Entornos

| Entorno | Cómo se ejecuta | Base de datos | Para qué |
|---|---|---|---|
| **Local** | `php artisan serve` + `npm run dev` (Vite con *proxy* a `:8000`) | SQLite | Desarrollo diario con recarga instantánea. |
| **Compose** | `docker compose up -d --build` | MySQL + Redis | Prueba integrada idéntica a producción; la demo del TP. |
| **CI** | GitHub Actions | SQLite en memoria | Pruebas automatizadas en cada *push*. |
| **Producción / demo remota** | `docker compose pull && up -d` con imágenes `:SHA` | MySQL + Redis | Despliegue de artefactos inmutables ya probados. |

### 6.3 Integración y entrega continua

`[FIGURA 6 — 06-pipeline-cicd.png: Pipeline CI/CD]`

El pipeline (GitHub Actions, `.github/workflows/ci.yml`) tiene tres trabajos:

1. **Backend · Pest** — `composer install`, Pint en modo verificación, `php artisan test`.
2. **Frontend · Lint + Build** — `npm ci`, oxlint, `tsc -b && vite build`.
3. **Docker · Build & Push** — sólo si 1 y 2 pasaron **y** la rama es `main`: construye ambas imágenes y las publica en GitHub Container Registry etiquetadas con el **SHA del commit** y `latest`.

**Inmutabilidad.** Cada *build* produce una imagen que nunca se modifica; lo que pasó las pruebas es exactamente lo que se despliega. Un *rollback* consiste en volver a desplegar el SHA anterior. Un *pull request* con pruebas rotas no puede llegar a `main` ni generar imagen.

---

## 7. Observabilidad

### 7.1 Registros (logs)

El backend emite logs **estructurados en JSON** mediante Monolog (`JsonFormatter`) por la salida estándar del contenedor, en los niveles INFO, WARNING y ERROR. El middleware `AssignTraceId`:

- acepta un `X-Trace-Id` entrante o genera un UUID;
- lo inyecta en **todos** los logs de esa petición (`Log::shareContext`);
- lo devuelve en la respuesta para que el frontend o el usuario puedan citarlo;
- registra una línea `http.request` por petición con método, ruta, código de estado, duración y usuario.

```json
{"message":"http.request","context":{"trace_id":"c194bc96-7181-4812-80d2-cd4950ad053c",
 "method":"PATCH","path":"api/v1/tickets/7/status","status":200,"duration_ms":25,"user_id":2},
 "level_name":"INFO","datetime":"2026-10-26T23:17:40+00:00"}
```

Los eventos de negocio (`ticket.created`, `ticket.status_changed`) comparten el mismo `trace_id`, lo que permite reconstruir todo lo que ocurrió en una petición.

### 7.2 Métricas

El backend expone `GET /metrics` en formato Prometheus:

| Métrica | Tipo | Qué mide |
|---|---|---|
| `antibugs_http_requests_total{status_class}` | counter | Peticiones por clase 2xx / 3xx / 4xx / 5xx |
| `antibugs_http_request_duration_ms_sum` | counter | Duración acumulada → latencia media |
| `antibugs_tickets_total{status}` | gauge | Tickets por estado (métrica de negocio) |
| `antibugs_tickets_created_last_hour` | gauge | Tickets creados en la última hora |

### 7.3 Herramientas

`[FIGURA 7 — 07-observabilidad.png: Flujo de observabilidad y trace_id]`

| Herramienta | Función |
|---|---|
| **Prometheus** | Recolecta `/metrics` cada 15 s y evalúa las reglas de alerta. |
| **Monolog → Promtail → Loki** | Monolog produce JSON; Promtail lee la salida de los contenedores y etiqueta por servicio y nivel; Loki indexa y permite consultar por `trace_id`. |
| **Grafana** | Dashboard provisionado automáticamente *AntiBugs · Monitoreo del servicio*: estado del backend, **tasa de errores 5xx frente al umbral del 5 %**, peticiones por minuto, latencia, tickets por estado, backlog en el tiempo y paneles de logs en los que el `trace_id` es clicable. |

### 7.4 Alertas y escenario de fallo

| Alerta | Condición | Severidad |
|---|---|---|
| **HighErrorRate** | errores 5xx > **5 % del tráfico durante 5 minutos** | crítica |
| **BackendDown** | el backend no responde al *scrape* durante 1 minuto | crítica |
| **TicketBacklogGrowing** | más de 50 tickets abiertos durante 30 minutos | advertencia |

**Procedimiento de diagnóstico** (escenario: sobrecarga o caída de la base de datos):

1. *Detección.* El panel de tasa de errores pasa a rojo y `HighErrorRate` entra en estado *firing*.
2. *Acotar.* En el panel de peticiones por clase se observa desde cuándo; en *Errores y advertencias* aparecen las excepciones con su `trace_id`.
3. *Correlacionar.* Un clic en el `trace_id` muestra todas las líneas de esa petición: la entrada HTTP, los eventos de negocio y la excepción con archivo y línea.
4. *Decidir.*
   - Si no hay logs del backend para ese `trace_id` y el frontend recibió 502/504 → la falla está en la **red Frontend → Backend** (NGINX no alcanza a FrankenPHP): reiniciar el contenedor `backend`.
   - Si la excepción es `PDOException` / *connection refused* → la falla es **Backend → DB**: revisar o reiniciar `mysql`.
   - Si el log existe y la excepción es de aplicación → es un **error de lógica**: el `trace_id` acompaña al ticket interno de corrección.

Este escenario es reproducible en la demo: al detener el contenedor de MySQL, cada petición que toca datos responde 500; en cinco minutos la tasa supera el umbral y la alerta se dispara; al restaurar la base, la alerta se resuelve sola.

---

## 8. Presentación grupal

| Integrante | Secciones | Minutos | Demo |
|---|---|---|---|
| 1 | Problema, propuesta, roles y alcance (§1) | 3 | Login como usuario → crear ticket "urgente" → prioridad CRITICAL inferida. |
| 2 | Arquitectura, patrones y API (§2, §4) | 6 | Login técnico → cola por prioridad → *En proceso* → **modal de justificación** → historial. Usuario intenta resolver → 403. |
| 3 | Frontend y pantallas (§3) | 3 | Resolver → confirmación de cierre por el usuario → Estadísticas. |
| 4 | Testing, Docker/DevOps y observabilidad (§5, §6, §7) | 6 | Pipeline en verde en GitHub → Grafana → `docker compose stop mysql` → la tasa de 5xx sube → `trace_id` en Loki → `start mysql`. |

Soporte visual: diapositivas con las figuras 1–7 y capturas de las pantallas. Cada integrante cierra su parte respondiendo *"¿por qué esta decisión?"* con la alternativa descartada (tabla 2.4). Capturas de respaldo de cada paso de la demo por si falla la conexión.

---

## Anexo A · Ejecución del prototipo

```bash
git clone https://github.com/epirox/antibugs && cd antibugs
cp .env.example .env
docker compose up -d --build                       # app completa en http://localhost
docker compose --profile observability up -d       # + Grafana en http://localhost:3000
docker compose exec backend php artisan antibugs:seed-history   # historia para los gráficos
```

Cuentas de demostración (contraseña `password`): `usuario@antibugs.local`, `tecnico@antibugs.local`, `admin@antibugs.local`.

**Alcance de lo implementado:** los nueve casos de uso, la API completa, las seis pantallas, 49 pruebas automatizadas, el pipeline de CI/CD con publicación de imágenes y el stack de observabilidad con dashboard y alertas. Documentación ampliada en `docs/` del repositorio.
