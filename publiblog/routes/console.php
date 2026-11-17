<?php

use Illuminate\Support\Facades\Schedule;

/*
| Cola de base de datos procesada por cron (F2-4). En el servidor basta UNA línea de cron:
|
|     * * * * * cd /ruta/a/publiblog && php artisan schedule:run >> /dev/null 2>&1
|
| El trabajador procesa lo pendiente y se detiene; el solapamiento se evita con un bloqueo.
*/
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// Publicaciones que se quedaron "en curso" porque el proceso murió.
Schedule::command('deployments:reap')->everyFiveMinutes();

// Imágenes que ningún artículo usa ya (con 14 días de gracia).
Schedule::command('blog:clean-media --days=14')->dailyAt('03:30')->withoutOverlapping();

// Comprobación semanal de que los servidores de los clientes siguen como el CMS los dejó (opt-in).
if (config('publiblog.verify_weekly')) {
    Schedule::command('blog:verify --queue')->weeklyOn(1, '04:00');
}
