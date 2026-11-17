<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\BlogVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Un lote de la verificación del servidor; encola el siguiente con el cursor. */
class VerifyBlog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $deploymentId, public readonly int $afterId = 0)
    {
    }

    public function handle(BlogVerifier $verifier): void
    {
        $deployment = Deployment::find($this->deploymentId);

        if (! $deployment) {
            return;
        }

        $next = $verifier->runBatch($deployment, $this->afterId);

        if ($next !== null) {
            static::dispatch($this->deploymentId, $next);
        }
    }

    public function failed(Throwable $e): void
    {
        Deployment::whereKey($this->deploymentId)->whereIn('status', ['queued', 'running'])->update([
            'status' => Deployment::STATUS_ERROR,
            'error_code' => 'incomplete',
            'error_message' => 'La comprobación se interrumpió. Puedes volver a lanzarla.',
            'finished_at' => now(),
        ]);
    }
}
