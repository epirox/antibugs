# 7 · Guion de la presentación

**Duración objetivo: 20 minutos** (18 de exposición + 2 de margen) y luego preguntas. Si el docente fija otro tiempo, escalar proporcionalmente: cada bloque indica su peso.

Cada diapositiva lista **título · contenido · figura · qué decir en 20–30 s · la pregunta "¿por qué?" que ese integrante debe poder responder**. Las figuras están en [`diagramas/`](./diagramas/) y las capturas en [`capturas/`](./capturas/).

## Reparto y tiempos

| Bloque | Integrante | Diapositivas | Tiempo | Criterios de la rúbrica que cubre |
|---|---|---|---|---|
| A · Problema y propuesta | 1 | 1–4 | 3 min | §1 Descripción (10) |
| B · Arquitectura y backend | 2 | 5–10 | 6 min | §2 Arquitectura (20) · §4 Backend (15) |
| C · Frontend | 3 | 11–13 | 3 min | §3 Frontend (10) |
| D · Testing, DevOps y observabilidad | 4 | 14–19 | 6 min | §5 Testing (10) · §6 Docker (15) · §7 Observabilidad (10) |
| Cierre | todos | 20 | 1 min | §8 Presentación (10) |

> Regla de oro: **cada integrante termina su bloque con una decisión y su alternativa descartada**. La rúbrica evalúa "capacidad de justificar decisiones".

---

## Bloque A · Problema y propuesta — Integrante 1 (3 min)

### Diapositiva 1 · Portada
- **AntiBugs — Sistema de Gestión de Soporte Técnico**
- *Controla la verdadera plaga en tu sistema.*
- Integrantes · Programación de Vanguardia · 2026 · enlace al repo (QR opcional)
- Figura: logo 🐛 / captura `01-login.png` de fondo atenuada

### Diapositiva 2 · El problema
- Sin fuente única de verdad: pedidos por chat, mail y de palabra
- Consecuencias: **latencia** en la resolución · **silos** de información · **sin trazabilidad**
- Pregunta disparadora: *"¿Quién tiene mi ticket y por qué sigue parado?"*
- Qué decir: anclar en un ejemplo cotidiano (la impresora de facturación) y nombrar las tres consecuencias.

### Diapositiva 3 · La propuesta y los roles
- Plataforma web desacoplada: API REST + SPA, con flujo de estados **auditado**
- Tres roles: **Usuario** reporta y confirma · **Técnico** resuelve · **Administrador** controla y mide
- Objetivos medibles: ↓ tiempo de resolución · 100 % de cambios con justificación · estado visible sin preguntar
- Figura: tabla de roles (3 columnas) o íconos

### Diapositiva 4 · Alcance: 9 casos de uso
- CU-U01…03 (usuario) · CU-T01…03 (técnico) · CU-A01…03 (admin) en una grilla 3×3
- Resaltar el **crítico**: CU-T02 *actualizar estado con validación por rol y justificación obligatoria*
- Fuera de alcance (una línea): SLA automático, base de conocimiento, integraciones externas, app móvil
- **¿Por qué?** *"¿Por qué acotaron así?"* → Coherencia: todo lo incluido gira en torno al ciclo de vida del ticket; lo excluido no aporta al problema de trazabilidad y agregaría complejidad sin fundamento.

---

## Bloque B · Arquitectura y backend — Integrante 2 (6 min)

### Diapositiva 5 · Arquitectura en tres capas
- Figura: **`01-arquitectura.png`** a pantalla completa
- Presentación (React + NGINX) · Backend (Laravel, el "cerebro") · Persistencia (MySQL + Redis)
- Una frase por capa: *qué hace y qué NO hace* (el frontend no decide; el controlador no piensa; la base no tiene lógica)
- Qué decir: recorrer la figura de arriba hacia abajo siguiendo una petición.

### Diapositiva 6 · Anatomía de una petición
- Figura: **`02-secuencia-cambio-estado.png`**
- Middleware (trace_id + JWT) → FormRequest → Controller → DTO → **Service** → State Machine → Repository → DB
- Zona azul = transacción atómica: si una regla falla, **nada** se escribe
- Tres salidas posibles: 200 · 403 (rol) · 422 (transición)
- Qué decir: "el controlador son 4 líneas; toda la inteligencia está en el servicio y se prueba sin HTTP".

### Diapositiva 7 · Patrón State Machine
- Figura: **`03-maquina-de-estados.png`**
- 6 estados · tabla de transiciones · tabla de roles → **dos tablas, una clase**
- Reglas transversales: justificación obligatoria · RESOLVED exige solución + técnico · CLOSED sólo el creador
- **¿Por qué?** *"¿Por qué no `if` en el controlador?"* → Las reglas son datos: se prueban unitariamente (10 tests parametrizados), agregar un estado no toca otros componentes, y el bloqueo pesimista evita que dos técnicos tomen el mismo ticket.

### Diapositiva 8 · Repository y DTO
- **Repository**: el servicio depende de una interfaz; `CachedTaxonomyRepository` agrega Redis sin que el servicio lo sepa
- **DTO**: contrato inmutable controlador → servicio; la API dice `categoryId`, el dominio `category_id`
- Mini-diagrama: `Controller → DTO → Service → Interface ← Eloquent / Cached`
- **¿Por qué?** *"¿Repository si Eloquent ya abstrae?"* → Testeo con dobles, caché transparente, cero SQL en la lógica de negocio.

### Diapositiva 9 · Decisiones y alternativas descartadas
- Tabla de 4 filas: **JWT stateless** vs sesiones · **Redis** vs caché en DB · **NGINX proxy** vs CORS · **reglas solo en backend** vs validar en ambos
- Una justificación de una línea por fila
- Qué decir: elegir **una** y desarrollarla 30 s (recomendado: JWT → escalado horizontal + blacklist en Redis para el logout).

### Diapositiva 10 · API REST
- Tabla resumida de endpoints (auth · tickets · comments · users · reports) con el rol de acceso
- Ejemplo a dos columnas: **request** `PATCH /tickets/7/status` · **response** 200 con `summary`, `allowedTransitions`, `history`
- Debajo, los dos errores: `403 "El rol USER no puede…"` · `422 "La transición OPEN → RESOLVED no está permitida"`
- Qué decir: "la respuesta le dice al frontend qué puede hacer a continuación; el frontend no lo calcula".

---

## Bloque C · Frontend — Integrante 3 (3 min)

### Diapositiva 11 · Tecnología y estructura
- **React 19 + TypeScript + Vite**; `react-router-dom`; Recharts (carga diferida)
- Estructura: `api/` (cliente tipado) · `hooks/useAuth` · `components/` · `pages/`
- Rutas protegidas: `RequireAuth` (sesión) + `RequireRole` (rol)
- **¿Por qué?** *"¿Por qué TypeScript?"* → Los tipos `Ticket`, `User`, `TicketStatus` son el contrato con el API: un cambio se detecta al compilar, no en producción. Vite: build estático que NGINX sirve sin Node.

### Diapositiva 12 · Pantallas
- Mosaico de 4 capturas: `04-dashboard-tecnico.png` · `03-nuevo-ticket.png` · `07-usuarios.png` · `08-estadisticas.png`
- Pie por captura: *cola por prioridad con filtros* · *alta con prioridad automática* · *gestión de roles* · *KPIs e historia de resolución*
- Qué decir: señalar que el dashboard cambia según el rol (usuario: sus tickets; técnico: la cola).

### Diapositiva 13 · Interacción con el backend
- Captura: **`06-modal-justificacion.png`** (la ventana de *justificación obligatoria*)
- Flujo: clic en transición → modal → `PATCH` → la respuesta reemplaza el ticket en pantalla (estado, técnico, historial)
- Los botones de transición se pintan desde `allowedTransitions`; los 422 se muestran campo por campo
- **¿Por qué?** *"¿Por qué el frontend no valida permisos?"* → Los oculta para la experiencia, pero la decisión es del backend; una URL forzada recibe 403. Una sola fuente de verdad para las reglas.

---

## Bloque D · Testing, DevOps y observabilidad — Integrante 4 (6 min)

### Diapositiva 14 · Estrategia de testing
- Pirámide: **10 unitarias** (State Machine, sin DB) · **39 de integración** (endpoints sobre SQLite en memoria) · lint + tipado del frontend
- Herramienta: **Pest** — nativo de Laravel: `RefreshDatabase`, factories, `actingAs` con JWT, `Notification::fake()`
- Cifra: **49 tests · 162 aserciones · en cada push**
- **¿Por qué?** *"¿Por qué Pest y no Jest?"* → Jest es para JavaScript; el backend es PHP. Pest corre sobre PHPUnit con la integración de Laravel lista.

### Diapositiva 15 · Los tres casos de prueba
- Tabla: **Caso 1** crear ticket → 201 + historial · **Caso 2** usuario común resuelve → 403, estado intacto · **Caso 3** ticket 9999 → 404
- Fila extra: *regresión real* — la caché de Redis devolvía 500 porque Laravel 13 bloquea deserializar objetos; el test lo impide para siempre
- Qué decir: "los tres casos de la consigna existen con ese nombre en el código y pasan en CI".

### Diapositiva 16 · Contenedores
- Figura: **`05-docker-compose.png`**
- 5 servicios: frontend (NGINX + build estático) · backend (FrankenPHP) · queue · MySQL · Redis — más el perfil `observability`
- Orquestación: `healthcheck` + `depends_on: service_healthy`; el *entrypoint* espera, migra y arranca
- **¿Por qué?** *"¿Por qué NGINX para el frontend?"* → React se precompila a estático; NGINX es mínimo, rápido y además hace de proxy `/api` → sin CORS.

### Diapositiva 17 · Pipeline CI/CD y entornos
- Figura: **`06-pipeline-cicd.png`**
- Push → Pest + Pint ∥ lint + build → gate → imágenes **inmutables `:SHA`** → GHCR → `compose pull && up`
- Entornos: Local (SQLite) · Compose (MySQL+Redis) · CI · Producción/demo
- **¿Por qué?** *"¿Por qué etiquetar por SHA?"* → Lo que pasó las pruebas es exactamente lo que se despliega; un rollback es redeplegar el SHA anterior.

### Diapositiva 18 · Observabilidad
- Figura: **`07-observabilidad.png`**
- Logs JSON (Monolog) con **`trace_id`** por petición → Promtail → Loki
- Métricas `/metrics` (requests por clase, latencia, tickets por estado) → Prometheus
- Grafana: dashboard provisionado · alerta **HighErrorRate: 5xx > 5 % en 5 min**
- Qué decir: "el `trace_id` viaja en el header de respuesta: un usuario puede citarlo y nosotros vemos todo lo que pasó en esa petición".

### Diapositiva 19 · Escenario de fallo
- Captura: dashboard de Grafana (tomarla en el navegador propio: `http://localhost:3000`)
- Árbol de decisión en 3 ramas: *sin logs del backend* → red Frontend→Backend · *PDOException* → Backend→DB · *excepción de app* → bug con `file:line`
- Cómo se reproduce en vivo: `docker compose stop mysql` → tasa de 5xx sube → alerta *firing* → `start mysql` → se resuelve sola
- **¿Por qué?** *"¿Por qué el umbral del 5 %?"* → Un 500 aislado no justifica despertar a nadie; un 5 % sostenido 5 minutos indica una causa sistémica (DB caída, despliegue roto), no ruido.

---

## Cierre — todos (1 min)

### Diapositiva 20 · Resumen y demo
- Una línea por criterio: diseño en capas · State Machine transaccional · API consistente · 49 tests · imágenes inmutables · monitoreo con trace_id
- Enlace al repositorio + QR · *"Preguntas"*
- Transición a la demo en vivo (o cerrar con ella si se hizo intercalada)

---

## Demo en vivo (≈6 min, intercalada o al final)

Preparación previa (10 min antes): `docker compose --profile observability up -d`, `antibugs:seed-history --fresh`, Grafana abierto en otra pestaña, Postman/Insomnia con el `PATCH` del 403 listo. **Capturas de respaldo** de cada paso por si falla la conexión.

| Paso | Quién | Acción | Qué señalar |
|---|---|---|---|
| 1 | Int. 1 | Login `usuario@antibugs.local` → *Nuevo ticket* → título "Fallo en impresora", descripción con "urgente", categoría Hardware, prioridad *Automática* → Crear | Prioridad **CRITICAL inferida**; `summary` "Sin técnico asignado – Abierto"; historial con la entrada inicial |
| 2 | Int. 2 | Login `tecnico@antibugs.local` → cola agrupada por prioridad → abrir el ticket → **→ En proceso** → modal → escribir justificación → Confirmar | Cola ordenada por `priority.level`; la modal **no deja continuar sin justificación**; el historial crece y el técnico queda asignado |
| 3 | Int. 2 | Desde Postman con el token del usuario: `PATCH …/status {status: RESOLVED}` | **403** con mensaje claro: el backend decide, no el frontend |
| 4 | Int. 3 | Técnico → **→ Resuelto** (con solución) → login usuario → **→ Cerrado** → login admin → *Estadísticas* | Flujo completo hasta CLOSED; notificación al usuario (bandeja en `storage/logs` o tabla `notifications`); backlog y rendimiento por técnico |
| 5 | Int. 4 | Grafana → mostrar dashboard → terminal: `docker compose stop mysql` → refrescar la app 10–15 veces → panel de 5xx sube → clic en un `trace_id` en Loki → `docker compose start mysql` | Detección → correlación → diagnóstico; la alerta tarda 5 min en pasar a *firing*: mostrar el estado *pending* en Prometheus `/alerts` si no hay tiempo |

## Checklist del día

- [ ] Docker Desktop iniciado · `docker compose --profile observability ps` todo *healthy*
- [ ] `php artisan antibugs:seed-history --fresh` ejecutado (gráficos con datos)
- [ ] Pestañas abiertas: app (`localhost`), Grafana (`localhost:3000`), Prometheus `/alerts`, repo en GitHub con el último pipeline en verde
- [ ] Postman con el `PATCH` del 403 preparado y token del usuario vigente (TTL 60 min: renovar antes)
- [ ] Capturas de respaldo (`docs/capturas/`, `docs/diagramas/`) en las diapositivas
- [ ] Cronómetro visible; un integrante controla el tiempo
- [ ] Cada uno tiene su "¿por qué?" ensayado en voz alta
