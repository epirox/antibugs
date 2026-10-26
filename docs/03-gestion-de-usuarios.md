# 3 · Gestión de usuarios y roles (CU-A01)

## 3.1 Objetivo

Dar al administrador **control total sobre quién accede a qué**: alta, modificación, cambio de rol y baja de usuarios, con las protecciones necesarias para no dejar el sistema sin administradores.

## 3.2 Roles y matriz de permisos

| Acción | USER | TECHNICIAN | ADMIN |
|---|:-:|:-:|:-:|
| Iniciar sesión / ver perfil | ✅ | ✅ | ✅ |
| Crear ticket | ✅ | ✅ | ✅ |
| Ver tickets | sólo propios | todos | todos |
| Comentar | en los propios | en todos | en todos |
| Tomar / asignar / escalar ticket | ❌ | ✅ | ✅ |
| Cambiar estado (IN_PROGRESS, PENDING_INFO, RESOLVED…) | ❌ | ✅ | ✅ |
| Confirmar cierre (RESOLVED → CLOSED) | sólo del propio | ❌ | ✅ |
| Listar técnicos (`/technicians`) | ❌ | ✅ | ✅ |
| Gestionar usuarios (`/users`) | ❌ | ❌ | ✅ |
| Configurar categorías / prioridades | ❌ | ❌ | ✅ |
| Estadísticas y exportación | ❌ | ❌ | ✅ |

**Dónde se aplica cada capa de control:**

| Capa | Mecanismo | Archivo |
|---|---|---|
| Ruta | middleware `role:ADMIN` | `routes/api.php` |
| Recurso | `TicketPolicy` (`view`, `comment`) | `app/Policies/TicketPolicy.php` |
| Transición | `TicketStateMachine::assertTransition()` | `app/Services/TicketStateMachine.php` |
| UI | `RequireRole` + enlaces condicionales en la barra | `frontend/src/App.tsx`, `components/Layout.tsx` |

> La UI oculta lo que el rol no puede hacer, pero **la decisión final es siempre del backend**: un usuario que fuerce una URL o una llamada HTTP recibe 403.

## 3.3 El rol dentro del JWT

El modelo `User` implementa `JWTSubject` e incluye el rol como *custom claim*:

```json
{
  "sub": 2,
  "role": "TECHNICIAN",
  "iat": 1761517200,
  "exp": 1761520800
}
```

El frontend lee `user.role` de la respuesta de `/auth/login` (no decodifica el token) para decidir qué mostrar. Si un admin cambia el rol de un usuario, el cambio se refleja en el próximo login o *refresh* del token.

## 3.4 API

| Método | Ruta | Descripción | Respuesta |
|---|---|---|---|
| GET | `/api/v1/users?role=technician&page=2` | Listado paginado (20 por página), filtro opcional por rol | 200 · `{ data[], links, meta }` |
| POST | `/api/v1/users` | Alta | 201 · `{ data: user }` |
| PATCH | `/api/v1/users/{id}` | Modificación parcial (nombre, email, rol, contraseña) | 200 · `{ data: user }` |
| DELETE | `/api/v1/users/{id}` | Baja | 204 sin cuerpo |
| GET | `/api/v1/technicians` | Técnicos + admins (para escalar un ticket); accesible a todo el staff | 200 · `{ data[] }` |

### Validaciones (`StoreUserRequest`)

| Campo | Alta | Edición |
|---|---|---|
| `name` | requerido, ≤ 255 | opcional |
| `email` | requerido, formato email, **único** | opcional, único ignorando el propio |
| `password` | requerido, ≥ 8 caracteres | opcional (vacío = no cambia); se guarda *hasheada* por el cast `hashed` |
| `role` | requerido, ∈ {USER, TECHNICIAN, ADMIN} | opcional |

### Reglas de protección

| Regla | Código | Motivo |
|---|---|---|
| Un admin no puede **eliminarse a sí mismo** | 422 | Evita quedarse sin acceso administrativo. |
| Un admin no puede **quitarse su propio rol ADMIN** | 422 | Ídem; sí puede cambiar su nombre/email/contraseña. |
| Sólo ADMIN accede a `/users` | 403 | Middleware `role:ADMIN`. |

### Efectos de eliminar un usuario

Definidos en las claves foráneas de la migración de `tickets`:

- Tickets **creados** por el usuario → se eliminan en cascada (`cascadeOnDelete`).
- Tickets **asignados** al usuario → quedan sin técnico (`nullOnDelete`), visibles de nuevo en la cola.
- Comentarios e historial del usuario → se eliminan en cascada.

La pantalla avisa de esto antes de confirmar.

### Ejemplo

```http
POST /api/v1/users
Authorization: Bearer <jwt-admin>
Content-Type: application/json

{ "name": "Lucía Soporte", "email": "lucia@antibugs.local", "password": "secreta123", "role": "TECHNICIAN" }
```

```http
HTTP/1.1 201 Created
{ "data": { "id": 4, "name": "Lucía Soporte", "email": "lucia@antibugs.local", "role": "TECHNICIAN", "createdAt": "2026-10-26T22:20:11.000000Z" } }
```

## 3.5 Pantalla *Usuarios y roles* (`/admin/users`)

Archivo: `frontend/src/pages/UsersPage.tsx`. Visible sólo para ADMIN (enlace **Usuarios** en la barra).

```
┌──────────────────────────────────────────────────────────────────────┐
│ Usuarios y roles                                   [+ Nuevo usuario] │
├──────────────────────────────────────────────────────────────────────┤
│ Rol [Todos ▾]   3 usuario(s)                                         │
├────┬──────────────────────┬──────────────────────┬──────────┬────────┤
│ #  │ Nombre               │ Email                │ Rol      │ Alta   │
├────┼──────────────────────┼──────────────────────┼──────────┼────────┤
│ 1  │ Admin AntiBugs (vos) │ admin@antibugs.local │ Admin    │ …  [Editar] [Eliminar⊘] │
│ 2  │ Juan Pérez           │ tecnico@…            │ Técnico  │ …  [Editar] [Eliminar]  │
│ 3  │ María Cliente        │ usuario@…            │ Usuario  │ …  [Editar] [Eliminar]  │
└────┴──────────────────────┴──────────────────────┴──────────┴────────┘
                      ‹ Anterior   Página 1 de 1   Siguiente ›
```

| Elemento | Comportamiento |
|---|---|
| Filtro **Rol** | Recarga el listado con `?role=…` y vuelve a la página 1. |
| **+ Nuevo usuario** | Abre el modal de alta (nombre, email, rol, contraseña obligatoria). |
| **Editar** | Mismo modal precargado. La contraseña es opcional. Si editás tu propio usuario, el selector de rol queda deshabilitado con la leyenda *"No podés cambiar tu propio rol"*. |
| **Eliminar** | Modal de confirmación que explica el efecto sobre los tickets. Deshabilitado en tu propia fila. |
| Errores | Los errores 422 del backend se muestran debajo de cada campo (`email` duplicado, contraseña corta, etc.). |
| Paginación | Aparece sólo si hay más de una página. |

Insignias de rol: **Administrador** (violeta), **Técnico** (cian), **Usuario** (gris).

## 3.6 Flujo típico: incorporar un técnico nuevo

1. Admin → **Usuarios** → **+ Nuevo usuario**.
2. Completa nombre, email, elige rol **Técnico** y una contraseña inicial → **Crear usuario**.
3. El técnico inicia sesión; ve la **Cola de incidentes** ordenada por prioridad.
4. Desde el detalle de un ticket puede tomarlo (**→ En proceso**) o, si corresponde a otra especialidad, escalarlo eligiendo un colega en **Asignar a** — la lista sale de `/technicians`.

## 3.7 Pruebas relacionadas

`tests/Feature/UserManagementTest.php` cubre CRUD completo, validaciones, auto-protección del admin y el bloqueo a otros roles. Ver [02-casos-de-prueba.md](./02-casos-de-prueba.md#gestión-de-usuarios--testsfeatureusermanagementtestphp).
