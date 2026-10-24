<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(TaxonomySeeder::class);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@antibugs.local'],
            ['name' => 'Admin AntiBugs', 'password' => 'password', 'role' => Role::ADMIN],
        );

        $tech = User::query()->firstOrCreate(
            ['email' => 'tecnico@antibugs.local'],
            ['name' => 'Juan Pérez', 'password' => 'password', 'role' => Role::TECHNICIAN],
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'usuario@antibugs.local'],
            ['name' => 'María Cliente', 'password' => 'password', 'role' => Role::USER],
        );

        if (Ticket::query()->exists()) {
            return;
        }

        $categories = Category::all();
        $high = Priority::query()->where('name', 'HIGH')->firstOrFail();
        $medium = Priority::query()->where('name', 'MEDIUM')->firstOrFail();
        $low = Priority::query()->where('name', 'LOW')->firstOrFail();

        // Sin factories: Faker es dependencia de desarrollo y este seeder corre también en producción (Docker).
        $open = Ticket::query()->create([
            'title' => 'Fallo en impresora',
            'description' => 'No imprime en red desde esta mañana.',
            'status' => TicketStatus::OPEN,
            'category_id' => $categories->firstWhere('name', 'Hardware')->id,
            'priority_id' => $high->id,
            'created_by' => $user->id,
        ]);
        $open->statusHistory()->create([
            'user_id' => $user->id,
            'to_status' => TicketStatus::OPEN,
            'justification' => 'Ticket creado por el usuario.',
        ]);

        $inProgress = Ticket::query()->create([
            'title' => 'No puedo acceder al correo',
            'description' => 'Outlook pide contraseña constantemente.',
            'status' => TicketStatus::IN_PROGRESS,
            'category_id' => $categories->firstWhere('name', 'Accesos y cuentas')->id,
            'priority_id' => $medium->id,
            'created_by' => $user->id,
            'assigned_to' => $tech->id,
        ]);
        $inProgress->statusHistory()->createMany([
            ['user_id' => $user->id, 'to_status' => TicketStatus::OPEN, 'justification' => 'Ticket creado por el usuario.'],
            ['user_id' => $tech->id, 'from_status' => TicketStatus::OPEN, 'to_status' => TicketStatus::IN_PROGRESS, 'assigned_to' => $tech->id, 'justification' => 'Tomo el caso, corresponde a mi especialidad.'],
        ]);

        $software = Ticket::query()->create([
            'title' => 'Solicitud de nuevo software',
            'description' => 'Necesitamos licencias de diseño para el área de marketing.',
            'status' => TicketStatus::OPEN,
            'category_id' => $categories->firstWhere('name', 'Software')->id,
            'priority_id' => $low->id,
            'created_by' => $admin->id,
        ]);
        $software->statusHistory()->create([
            'user_id' => $admin->id,
            'to_status' => TicketStatus::OPEN,
            'justification' => 'Ticket creado por el usuario.',
        ]);
    }
}
