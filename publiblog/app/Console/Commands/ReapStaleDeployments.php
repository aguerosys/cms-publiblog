<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Deployment;
use Illuminate\Console\Command;

/**
 * Si un proceso muere a mitad de una publicación (reinicio, límite de memoria, corte de luz) el
 * registro se queda en "en cola"/"en curso" para siempre. Pasado un margen se marca como error
 * para que el usuario pueda pulsar «Reintentar».
 */
class ReapStaleDeployments extends Command
{
    protected $signature = 'deployments:reap {--minutes=30 : Antigüedad a partir de la cual se considera atascado}';

    protected $description = 'Marca como error las publicaciones que llevan demasiado tiempo en curso';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $count = 0;

        Deployment::withoutGlobalScopes()
            ->whereIn('status', [Deployment::STATUS_QUEUED, Deployment::STATUS_RUNNING])
            ->where('updated_at', '<', $cutoff)
            ->each(function (Deployment $d) use (&$count) {
                $d->forceFill([
                    'status' => Deployment::STATUS_ERROR,
                    'error_code' => 'timeout',
                    'error_message' => 'La operación no terminó (el proceso se interrumpió). Puedes reintentarla.',
                    'finished_at' => now(),
                ])->save();

                if ($d->article_id) {
                    Article::withoutGlobalScopes()->whereKey($d->article_id)->where('last_deployment_id', $d->id)
                        ->update(['status' => Article::STATUS_ERROR]);
                }

                $count++;
            });

        $this->info("{$count} operaciones atascadas marcadas como error.");

        return self::SUCCESS;
    }
}
