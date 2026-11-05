<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Crea el único usuario de la fase 1 (superadmin con todos los permisos) desde el .env
     * y, solo en entornos que no son producción, el blog demoblog contra una carpeta local.
     */
    public function run(): void
    {
        $cfg = config('publiblog.admin');

        if ($cfg['email'] && $cfg['password']) {
            User::updateOrCreate(
                ['email' => $cfg['email']],
                ['name' => $cfg['name'], 'password' => $cfg['password'], 'role' => 'superadmin'],
            );
        } else {
            $this->command?->warn('Define PUBLIBLOG_ADMIN_EMAIL y PUBLIBLOG_ADMIN_PASSWORD en .env para crear el usuario administrador.');
        }

        if (! app()->environment('production')) {
            $this->call(DemoBlogSeeder::class);
        }
    }
}
