<?php

namespace Database\Seeders;

use App\Models\Blog;
use Illuminate\Database\Seeder;

/**
 * Blog de desarrollo: publica en ../demoblog/blog (carpeta local) y se verifica contra
 * `php -S localhost:8081 -t demoblog demoblog/router.php`. No se ejecuta en producción.
 */
class DemoBlogSeeder extends Seeder
{
    public function run(): void
    {
        $dir = realpath(base_path('../demoblog/blog')) ?: base_path('../demoblog/blog');

        Blog::updateOrCreate(
            ['domain' => 'localhost:8081'],
            [
                'name' => 'Demoblog (local)',
                'base_url' => 'http://localhost:8081',
                'remote_dir' => $dir,
                'locale' => 'es',
                'timezone' => 'Europe/Madrid',
                'posts_per_page' => 12,
                'template' => 'demoblog',
                'sftp_auth' => 'local',
                'verify_http' => true,
                'status' => Blog::STATUS_ACTIVE,
            ]
        );
    }
}
