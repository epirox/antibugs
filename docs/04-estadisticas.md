# 4 · Estadísticas y gráficos (CU-A03)

## 4.1 Objetivo

Que el administrador pueda **analizar el servicio** a partir de los datos transaccionales del sistema: cuántos tickets entran y salen, cuánto se tarda en resolverlos, dónde se concentra el volumen y cómo rinde cada técnico; y **exportar** esos datos.

Pantalla: `/admin/stats` (enlace **Estadísticas**, sólo ADMIN). Archivo: `frontend/src/pages/StatsPage.tsx`. Librería de gráficos: **Recharts 3** (SVG, componentes React, responsive), cargada con `React.lazy` para no engrosar el bundle de los demás roles.

## 4.2 Fuentes de datos

| Endpoint | Qué devuelve | Usado por |
|---|---|---|
| `GET /api/v1/reports/kpis` | Totales, promedio de resolución, desgloses por estado / categoría / prioridad / técnico | Tarjetas KPI y los 4 gráficos secundarios |
| `GET /api/v1/reports/timeline?days=N` | Serie diaria (N ∈ [1, 365]) de creados, resueltos, cerrados y backlog + resumen del período | Gráfico *Historia de resolución* |
| `GET /api/v1/reports/export` | CSV de todos los tickets | Botón **Exportar CSV** |

Todos los cálculos de tiempo se hacen en PHP (`Carbon`) para ser agnósticos al motor (MySQL en producción, SQLite en tests).

## 4.3 Tarjetas KPI

| Tarjeta | Cálculo |
|---|---|
| **Tickets totales** | `count(tickets)` |
| **Abiertos ahora** | tickets en OPEN, ASSIGNED, IN_PROGRESS o PENDING_INFO |
| **Creados últimas 24 h** | `created_at >= now() - 1 día` |
| **Tiempo promedio de resolución** | media de `resolved_at − created_at` sobre los tickets con `resolved_at` |

Formato de duración (`formatHours`): `< 1 h` → minutos · `< 48 h` → horas con un decimal · resto → días con un decimal.

## 4.4 Gráficos

### A. Historia de resolución (gráfico principal)

**Tipo:** `ComposedChart` — combina barras, línea y área sobre el mismo eje temporal.
**Rango:** selector **7 / 30 / 90 días**.

| Serie | Representación | Significado |
|---|---|---|
| **Creados** | barras azules | tickets con `created_at` ese día |
| **Resueltos** | barras verdes | tickets con `resolved_at` ese día |
| **Cerrados** | línea gris | tickets con `closed_at` ese día (confirmación del usuario) |
| **Backlog (abiertos)** | área ámbar | tickets abiertos acumulados al cierre del día |

**Cálculo del backlog** (en `ReportController::timeline`):

```
backlog_inicial = creados antes del rango − resueltos antes del rango
backlog(d)      = backlog(d−1) + creados(d) − resueltos(d)
```

Así la curva es continua aunque sólo se pida la última semana. Si el área **sube**, entra más trabajo del que se resuelve; si **baja**, el equipo está reduciendo la deuda.

El subtítulo resume el período: *creados · resueltos · tasa de resolución (resueltos ⁄ creados) · promedio de resolución*.

### B. Tickets por estado

**Tipo:** barras horizontales (`BarChart layout="vertical"`), una por estado con su color institucional (Abierto azul, En proceso ámbar, Resuelto verde, Cerrado gris…). Sólo se muestran estados con tickets. Permite ver de un vistazo cuánto trabajo está en cada etapa del flujo.

### C. Volumen por categoría

**Tipo:** donut (`PieChart` con `innerRadius`), porcentaje sobre cada porción y leyenda lateral con los nombres. Responde a *"¿dónde se concentra la demanda?"* (Hardware, Redes, Software…), insumo para dimensionar el equipo por especialidad.

### D. Tickets por prioridad

**Tipo:** barras verticales con colores semánticos — CRITICAL rojo, HIGH naranja, MEDIUM amarillo, LOW verde. Muestra si la carga es mayormente urgente o rutinaria.

### E. Rendimiento por técnico

**Tipo:** barras apiladas (`stackId`) por técnico: **Resueltos** (verde) + **En curso** (ámbar), seguidas de una tabla con el **promedio de resolución** individual. Sólo aparecen técnicos/admins con al menos un ticket asignado. Útil para balancear asignaciones y detectar cuellos de botella.

## 4.5 Exportación

El botón **⬇ Exportar CSV** descarga `tickets-AAAAMMDD-HHMMSS.csv` con: `id, title, status, category, priority, created_by, assigned_to, created_at, resolved_at`. Como el endpoint exige el Bearer JWT, el frontend hace `fetch` → `Blob` → enlace temporal de descarga (no se puede usar un `<a href>` directo). El backend lo genera con `streamDownload` y `chunk(500)` para no cargar todo en memoria.

## 4.6 Datos de demostración

Con una base recién creada los gráficos quedan casi vacíos. El comando

```bash
php artisan antibugs:seed-history                 # 45 días, hasta 3 tickets/día
php artisan antibugs:seed-history --days=90       # más historia
php artisan antibugs:seed-history --fresh         # borra los históricos previos y regenera
```

genera tickets con fechas en el pasado, ~80 % resueltos en 2–96 h, la mitad de ellos cerrados por el usuario, repartidos entre categorías, prioridades y técnicos, **con su historial de estados**. Es reproducible (semilla fija) y los tickets se identifican por su descripción, por lo que `--fresh` nunca toca datos reales.

En Docker:

```bash
docker compose exec backend php artisan antibugs:seed-history --fresh
```

## 4.7 Lectura sugerida para la presentación

1. **KPIs**: estado actual del servicio en cuatro números.
2. **Historia de resolución** a 30 días: *"entran X por día, resolvemos Y; el backlog bajó/subió de A a B"*.
3. Cambiar a **7 días** para mostrar la semana en curso.
4. **Por técnico**: distribución de carga y tiempos individuales.
5. **Exportar CSV** para análisis externo.

## 4.8 Pruebas relacionadas

`tests/Feature/ReportTest.php` valida la serie temporal (incluido el backlog inicial y el promedio de 48 h en un caso controlado con `Carbon::setTestNow`), los límites del rango, el desglose por técnico y el bloqueo a no administradores. `tests/Feature/SeedDemoHistoryTest.php` valida el generador de datos. Ver [02-casos-de-prueba.md](./02-casos-de-prueba.md#reportes--testsfeaturereporttestphp).
