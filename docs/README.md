# Documentación · AntiBugs

| # | Documento | Contenido |
|---|---|---|
| 1 | [Diseño del sistema](./01-diseno-del-sistema.md) | Problema, roles, casos de uso, arquitectura por capas, patrones (State Machine, Repository, DTO), modelo de datos, API, infraestructura y observabilidad. |
| 2 | [Casos de prueba](./02-casos-de-prueba.md) | Estrategia de testing, los 3 casos exigidos por el TP y el catálogo completo de los 49 tests. |
| 3 | [Gestión de usuarios](./03-gestion-de-usuarios.md) | CU-A01: matriz de permisos por rol, API `/users`, validaciones, reglas de protección y la pantalla *Usuarios y roles*. |
| 4 | [Estadísticas y gráficos](./04-estadisticas.md) | CU-A03: KPIs, los cinco gráficos (qué muestran y cómo se calculan), exportación CSV y datos de demostración. |
| 5 | [Observabilidad y monitoreo](./05-observabilidad.md) | Prometheus + Grafana + Loki: logs JSON con `trace_id`, métricas, dashboard provisionado, alertas y el procedimiento de diagnóstico del escenario de fallo. |
| 6 | [Diagramas](./06-diagramas.md) | Siete diagramas Mermaid (arquitectura, secuencia, máquina de estados, ER, Compose, CI/CD, observabilidad) con sus [PNG listos para pegar](./diagramas/). |

Para la puesta en marcha (Docker, desarrollo local, cuentas demo) ver el [README principal](../README.md).
