# 6 · Diagramas

Diagramas en formato [Mermaid](https://mermaid.js.org/): GitHub los renderiza automáticamente en este archivo. Las versiones en PNG para pegar en el documento o las diapositivas están en [`diagramas/`](./diagramas/).

| # | Diagrama | Sección del TP donde va |
|---|---|---|
| 1 | [Arquitectura por capas y componentes](#1-arquitectura-por-capas-y-componentes) | 2 · Arquitectura |
| 2 | [Secuencia: cambio de estado de un ticket](#2-secuencia-cambio-de-estado-de-un-ticket) | 2 · Arquitectura / 4 · Backend |
| 3 | [Máquina de estados del ticket](#3-máquina-de-estados-del-ticket) | 2 · Arquitectura (patrón State Machine) |
| 4 | [Modelo de datos (ER)](#4-modelo-de-datos-er) | 4 · Backend, POO y APIs |
| 5 | [Contenedores Docker Compose](#5-contenedores-docker-compose) | 6 · Docker, DevOps y despliegue |
| 6 | [Pipeline CI/CD](#6-pipeline-cicd) | 6 · Docker, DevOps y despliegue |
| 7 | [Flujo de observabilidad y trace_id](#7-flujo-de-observabilidad-y-trace_id) | 7 · Observabilidad |

---

## 1 · Arquitectura por capas y componentes

```mermaid
flowchart TB
    subgraph CLIENTE["Capa de presentación"]
        direction LR
        SPA["React 19 + TypeScript<br/>(Vite)"]
        NGINX["NGINX :80<br/>sirve la SPA<br/>proxy /api → backend"]
        SPA --- NGINX
    end

    subgraph BACKEND["Capa de backend · Laravel 13 (FrankenPHP :8000)"]
        direction LR
        MW["Middleware<br/>AssignTraceId · auth:api (JWT) · role"]
        CTRL["Controllers<br/><i>gateway HTTP: validan y delegan</i>"]
        REQ["FormRequests<br/>validación de entrada"]
        DTO["DTOs<br/>CreateTicketData · ChangeTicketStatusData"]
        SVC["Services<br/>TicketService · <b>TicketStateMachine</b>"]
        POL["Policies<br/>TicketPolicy"]
        REPO["Repositories (interfaces)<br/>TicketRepository · TaxonomyRepository"]
        ELO["Eloquent ORM"]
        NOTIF["Notifications<br/>(cola)"]

        MW --> CTRL
        REQ -.-> CTRL
        CTRL --> DTO --> SVC
        CTRL -.-> POL
        SVC --> REPO --> ELO
        SVC --> NOTIF
    end

    subgraph PERSISTENCIA["Capa de persistencia y caché"]
        direction LR
        MYSQL[("MySQL 8.4<br/>datos persistentes")]
        REDIS[("Redis 7<br/>caché · colas · blacklist JWT")]
    end

    NGINX -- "HTTPS / JSON<br/>Authorization: Bearer JWT" --> MW
    ELO --> MYSQL
    REPO -- "lecturas frecuentes<br/>(taxonomía)" --> REDIS
    NOTIF -- "jobs" --> REDIS
    MW -- "blacklist de tokens" --> REDIS

    classDef capa fill:#f8fafc,stroke:#94a3b8,stroke-width:1px,color:#0f172a;
    classDef core fill:#dbeafe,stroke:#2563eb,color:#0f172a;
    classDef db fill:#fef3c7,stroke:#d97706,color:#0f172a;
    class SPA,NGINX,MW,CTRL,REQ,DTO,POL,REPO,ELO,NOTIF capa;
    class SVC core;
    class MYSQL,REDIS db;
```

**Lectura:** el frontend nunca contiene reglas de negocio; el controlador sólo valida y delega; la lógica (transiciones, permisos, transaccionalidad) vive en la capa de servicios y es independiente de cómo llega la petición y de dónde se guarda el dato.

---

## 2 · Secuencia: cambio de estado de un ticket

`PATCH /api/v1/tickets/7/status` — el técnico toma un ticket abierto.

```mermaid
sequenceDiagram
    autonumber
    actor T as Técnico (SPA)
    participant N as NGINX
    participant MW as Middleware<br/>TraceId · JWT
    participant C as TicketController
    participant R as UpdateTicketStatusRequest
    participant S as TicketService
    participant SM as TicketStateMachine
    participant RP as TicketRepository
    participant DB as MySQL

    T->>N: PATCH /api/v1/tickets/7/status<br/>{status: IN_PROGRESS, justification: "…"}
    N->>MW: proxy + X-Trace-Id
    MW->>MW: valida JWT, resuelve usuario y rol
    MW->>C: request autenticada
    C->>R: validar (status ∈ enum, justification ≥ 10 chars)
    R-->>C: datos validados
    C->>S: changeStatus(7, ChangeTicketStatusData, actor)

    rect rgb(219, 234, 254)
        note over S,DB: DB::transaction — atómico
        S->>RP: lockForUpdate(7)
        RP->>DB: SELECT … FOR UPDATE
        DB-->>RP: ticket (OPEN)
        S->>SM: assertTransition(ticket, IN_PROGRESS, actor)
        alt rol no autorizado
            SM-->>S: AuthorizationException
            S-->>C: ⟵ 403 Forbidden
        else transición inválida
            SM-->>S: InvalidStatusTransitionException
            S-->>C: ⟵ 422 Unprocessable
        else OK
            SM-->>S: ✓
            S->>RP: update(status, assigned_to = actor)
            RP->>DB: UPDATE tickets
            S->>DB: INSERT ticket_status_histories<br/>(from, to, actor, justification)
        end
    end

    S-->>C: ticket actualizado
    C-->>MW: TicketResource (JSON)
    MW->>MW: log http.request {trace_id, status, duration}
    MW-->>N: 200 + X-Trace-Id
    N-->>T: {status, summary, allowedTransitions, history…}
```

---

## 3 · Máquina de estados del ticket

```mermaid
stateDiagram-v2
    direction LR

    [*] --> OPEN : usuario crea el ticket<br/>(CU-U01)

    OPEN --> ASSIGNED : técnico/admin asigna
    OPEN --> IN_PROGRESS : técnico toma el caso
    ASSIGNED --> IN_PROGRESS : técnico comienza
    ASSIGNED --> OPEN : devolver a la cola
    IN_PROGRESS --> PENDING_INFO : falta información del usuario
    PENDING_INFO --> IN_PROGRESS : usuario responde
    IN_PROGRESS --> ASSIGNED : escalar a otro técnico
    IN_PROGRESS --> RESOLVED : técnico registra la solución<br/>(requiere resolution + técnico asignado)
    RESOLVED --> CLOSED : <b>creador</b> confirma (o admin)
    RESOLVED --> IN_PROGRESS : usuario rechaza la solución
    CLOSED --> [*]

    note right of OPEN
        Toda transición exige
        <b>justificación escrita</b>
        y queda en el historial.
    end note

    note right of CLOSED
        Estado final:
        sin transiciones de salida.
    end note

    classDef trabajo fill:#fef3c7,stroke:#d97706
    classDef fin fill:#dcfce7,stroke:#059669
    classDef cerrado fill:#e5e7eb,stroke:#374151
    class ASSIGNED,IN_PROGRESS,PENDING_INFO trabajo
    class RESOLVED fin
    class CLOSED cerrado
```

| Transición hacia | Roles autorizados |
|---|---|
| OPEN, ASSIGNED, IN_PROGRESS, PENDING_INFO, RESOLVED | TECHNICIAN, ADMIN |
| CLOSED | USER (sólo el creador del ticket), ADMIN |

---

## 4 · Modelo de datos (ER)

```mermaid
erDiagram
    USERS {
        bigint id PK
        string name
        string email UK
        string password
        enum role "USER | TECHNICIAN | ADMIN"
        datetime created_at
    }

    CATEGORIES {
        bigint id PK
        string name UK
        string description
        boolean is_active
    }

    PRIORITIES {
        bigint id PK
        string name UK "LOW | MEDIUM | HIGH | CRITICAL"
        tinyint level UK "mayor = más urgente"
        int sla_hours
    }

    TICKETS {
        bigint id PK
        string title
        text description
        enum status "OPEN … CLOSED"
        bigint category_id FK
        bigint priority_id FK
        bigint created_by FK
        bigint assigned_to FK "nullable"
        text resolution "nullable"
        datetime resolved_at "nullable"
        datetime closed_at "nullable"
        datetime created_at
    }

    COMMENTS {
        bigint id PK
        bigint ticket_id FK
        bigint user_id FK
        text body
        string attachment_path "nullable"
        datetime created_at
    }

    TICKET_STATUS_HISTORIES {
        bigint id PK
        bigint ticket_id FK
        bigint user_id FK "quién hizo el cambio"
        enum from_status "nullable"
        enum to_status
        bigint assigned_to FK "nullable"
        text justification
        datetime created_at
    }

    NOTIFICATIONS {
        uuid id PK
        string notifiable_type
        bigint notifiable_id
        json data
        datetime read_at
    }

    USERS ||--o{ TICKETS : "crea (created_by)"
    USERS |o--o{ TICKETS : "atiende (assigned_to)"
    CATEGORIES ||--o{ TICKETS : clasifica
    PRIORITIES ||--o{ TICKETS : prioriza
    TICKETS ||--o{ COMMENTS : tiene
    USERS ||--o{ COMMENTS : escribe
    TICKETS ||--o{ TICKET_STATUS_HISTORIES : audita
    USERS ||--o{ TICKET_STATUS_HISTORIES : ejecuta
    USERS ||--o{ NOTIFICATIONS : recibe
```

---

## 5 · Contenedores Docker Compose

```mermaid
flowchart TB
    U(["👤 Usuario (navegador)"])

    subgraph NET["red bridge · antibugs"]
        direction TB

        subgraph APP["Stack principal · docker compose up -d"]
            FE["<b>frontend</b><br/>nginx:1.27-alpine<br/>build de React<br/>:80"]
            BE["<b>backend</b><br/>FrankenPHP + Laravel 13<br/>migra y siembra al arrancar<br/>:8000"]
            QW["<b>queue</b><br/>misma imagen<br/>php artisan queue:work"]
            MY[("<b>mysql</b><br/>mysql:8.4<br/>:3306")]
            RD[("<b>redis</b><br/>redis:7-alpine<br/>:6379")]
        end

        subgraph OBS["Perfil observability · --profile observability"]
            PR["<b>prometheus</b><br/>:9090<br/>scrape + alertas"]
            LK["<b>loki</b><br/>:3100"]
            PT["<b>promtail</b><br/>lee stdout de los contenedores"]
            GF["<b>grafana</b><br/>:3000<br/>dashboard provisionado"]
        end
    end

    subgraph VOL["Volúmenes persistentes"]
        V1[/mysql_data/]
        V2[/redis_data/]
        V3[/backend_storage<br/>adjuntos/]
        V4[/grafana_data · prometheus_data · loki_data/]
    end

    U -- ":80" --> FE
    FE -- "proxy /api, /storage" --> BE
    BE -- "PDO" --> MY
    BE -- "caché · colas · JWT blacklist" --> RD
    QW -- "consume jobs" --> RD
    QW -- "PDO" --> MY

    PR -- "GET /metrics cada 15 s" --> BE
    PT -. "docker.sock" .-> APP
    PT -- "push" --> LK
    GF --> PR
    GF --> LK

    MY --- V1
    RD --- V2
    BE --- V3
    QW --- V3
    OBS --- V4

    classDef app fill:#dbeafe,stroke:#2563eb,color:#0f172a;
    classDef data fill:#fef3c7,stroke:#d97706,color:#0f172a;
    classDef obs fill:#f3e8ff,stroke:#7c3aed,color:#0f172a;
    classDef vol fill:#f1f5f9,stroke:#94a3b8,color:#0f172a;
    class FE,BE,QW app;
    class MY,RD data;
    class PR,LK,PT,GF obs;
    class V1,V2,V3,V4 vol;
```

**Orden de arranque (orquestación):** `mysql` y `redis` → *healthcheck* OK → `backend` (el *entrypoint* espera los puertos, ejecuta `migrate` y `db:seed`) → *healthcheck* `/up` OK → `frontend` y `queue`. Cada servicio declara `depends_on: condition: service_healthy`.

---

## 6 · Pipeline CI/CD

```mermaid
flowchart TB
    DEV["👩‍💻 Desarrollador<br/>git push / pull request"]

    subgraph CI["GitHub Actions · en cada push y PR"]
        direction LR
        subgraph B["Backend · Pest"]
            direction TB
            B1["composer install"] --> B2["Pint --test<br/>(estilo)"] --> B3["php artisan test<br/>49 tests · SQLite en memoria"]
        end
        subgraph F["Frontend · Lint + Build"]
            direction TB
            F1["npm ci"] --> F2["oxlint"] --> F3["tsc -b && vite build"]
        end
    end

    GATE{"¿todo verde<br/>y rama main?"}
    FAIL["❌ PR bloqueado<br/>se notifica al autor"]

    subgraph CD["Docker · Build & Push"]
        direction LR
        D1["docker build backend<br/>(multi-stage: composer → FrankenPHP)"]
        D2["docker build frontend<br/>(multi-stage: node → nginx)"]
        D3["push a GHCR<br/><b>:SHA del commit</b> + :latest"]
        D1 --> D3
        D2 --> D3
    end

    REG[("ghcr.io/epirox/antibugs/<br/>backend · frontend")]

    subgraph ENVS["Entornos"]
        direction LR
        E1["Local<br/>php artisan serve + Vite<br/>SQLite"]
        E2["Compose<br/>imágenes :local<br/>MySQL + Redis"]
        E3["Producción / demo<br/>imágenes :SHA desde GHCR<br/>.env con secretos reales"]
    end

    DEV --> CI --> GATE
    GATE -- "sí" --> CD --> REG
    GATE -- "no" --> FAIL
    REG -- "docker compose pull && up -d" --> E3
    DEV -. "desarrollo diario" .-> E1
    DEV -. "prueba integrada" .-> E2

    classDef ok fill:#dcfce7,stroke:#059669,color:#0f172a;
    classDef bad fill:#fee2e2,stroke:#dc2626,color:#0f172a;
    classDef neutral fill:#f8fafc,stroke:#94a3b8,color:#0f172a;
    class B1,B2,B3,F1,F2,F3,D1,D2,D3 neutral;
    class REG,E3 ok;
    class FAIL bad;
```

**Inmutabilidad:** cada build genera una imagen etiquetada con el SHA del commit; lo que pasó por pruebas es exactamente lo que se despliega. Un *rollback* es volver a desplegar el SHA anterior.

---

## 7 · Flujo de observabilidad y trace_id

```mermaid
flowchart TB
    subgraph REQ["1 · Una petición instrumentada"]
        direction LR
        SPA["SPA React"] -- "GET /api/v1/tickets" --> NG["NGINX<br/>reenvía X-Trace-Id"]
        NG --> MW["AssignTraceId<br/>genera/propaga UUID<br/>Log::shareContext(trace_id)"]
        MW --> APP["TicketService<br/>log ticket.* con trace_id"]
        APP --> DB[("MySQL")]
        MW -. "respuesta + X-Trace-Id" .-> SPA
    end

    subgraph COL["2 · Recolección"]
        direction LR
        subgraph L["Logs"]
            LOGS["stdout JSON<br/>{message, context.trace_id,<br/>status, duration_ms}"] --> PT["Promtail<br/>label: level"] --> LOKI[("Loki")]
        end
        subgraph M["Métricas"]
            RDS[("Redis<br/>metrics:*")] --> MET["GET /metrics<br/>antibugs_http_requests_total<br/>antibugs_tickets_total…"] -- "scrape 15 s" --> PROM[("Prometheus")]
            PROM --> AL{{"HighErrorRate<br/>5xx / total > 5 %<br/>durante 5 min"}}
        end
    end

    subgraph DX["3 · Visualización y diagnóstico"]
        direction LR
        GF["Grafana<br/>dashboard AntiBugs"] -- "clic en trace_id" --> DIAG["Explore: todos los logs<br/>de esa petición"]
        DIAG --> Q1{"¿hay logs del backend<br/>para ese trace_id?"}
        Q1 -- "no → 502/504" --> NETF["Falla de red<br/>Frontend → Backend"]
        Q1 -- "sí, PDOException" --> DBF["Falla Backend → DB"]
        Q1 -- "sí, excepción de app" --> BUG["Bug de lógica<br/>(file:line en el log)"]
    end

    MW -- "escribe" --> LOGS
    MW -- "incrementa contadores" --> RDS
    LOKI --> GF
    PROM --> GF
    AL -- "firing" --> GF

    classDef store fill:#fef3c7,stroke:#d97706,color:#0f172a;
    classDef alert fill:#fee2e2,stroke:#dc2626,color:#0f172a;
    classDef ok fill:#dcfce7,stroke:#059669,color:#0f172a;
    class DB,RDS,LOKI,PROM store;
    class AL,NETF,DBF,BUG alert;
    class GF,DIAG ok;
```
