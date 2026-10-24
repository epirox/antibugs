<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Priority;
use Illuminate\Database\Seeder;

class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $priorities = [
            ['name' => 'LOW', 'level' => 1, 'sla_hours' => 72],
            ['name' => 'MEDIUM', 'level' => 2, 'sla_hours' => 24],
            ['name' => 'HIGH', 'level' => 3, 'sla_hours' => 8],
            ['name' => 'CRITICAL', 'level' => 4, 'sla_hours' => 2],
        ];

        foreach ($priorities as $priority) {
            Priority::query()->updateOrCreate(['name' => $priority['name']], $priority);
        }

        $categories = [
            ['name' => 'Hardware', 'description' => 'Equipos, periféricos e impresoras'],
            ['name' => 'Software', 'description' => 'Aplicaciones, licencias e instalaciones'],
            ['name' => 'Redes', 'description' => 'Conectividad, Wi-Fi y VPN'],
            ['name' => 'Accesos y cuentas', 'description' => 'Contraseñas, permisos y correo'],
            ['name' => 'Otros', 'description' => 'Consultas generales'],
        ];

        foreach ($categories as $category) {
            Category::query()->updateOrCreate(['name' => $category['name']], $category);
        }
    }
}
