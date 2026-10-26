# AntiBugs · Backend (Laravel 13)

API REST del Sistema de Gestión de Soporte Técnico. Documentación completa en [`../docs/`](../docs/README.md).

## Desarrollo local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed          # SQLite por defecto
php artisan serve                   # http://localhost:8000
```

Cuentas demo: `admin@antibugs.local`, `tecnico@antibugs.local`, `usuario@antibugs.local` (contraseña `password`).

## Comandos útiles

```bash
php artisan test                    # Pest (unitarias + feature)
vendor/bin/pint                     # formato de código
php artisan route:list --path=api   # endpoints
php artisan antibugs:seed-history   # tickets históricos para las estadísticas
```

## Estructura

```
app/
├── DTOs/                  Contratos controlador → servicio
├── Enums/                 Role, TicketStatus
├── Http/
│   ├── Controllers/Api/V1 Gateways HTTP (validan y delegan)
│   ├── Middleware/        AssignTraceId (observabilidad), EnsureUserHasRole
│   ├── Requests/          Validación de entrada (FormRequest)
│   └── Resources/         Serialización JSON
├── Models/                Eloquent
├── Notifications/         Correo + base de datos (CU-U03, CU-T03)
├── Policies/              TicketPolicy
├── Repositories/          Interfaces + implementación Eloquent / caché Redis
└── Services/              TicketService, TicketStateMachine
```
