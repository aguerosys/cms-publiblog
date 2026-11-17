<?php

namespace App\Jobs;

use App\Exceptions\PublishException;
use App\Models\Deployment;
use App\Services\BlogRegenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Un lote de la regeneración masiva. Al terminar encola el siguiente (con el cursor), así que cada
 * trabajo dura poco aunque el blog tenga miles de artículos y un corte solo pierde un lote.
 */
class RegenerateBlog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 40;

    public int $timeout = 600;

    public function __construct(public readonly int $deploymentId, public readonly int $afterId = 0)
    {
    }

    public function handle(BlogRegenerator $regenerator): void
    {
        $deployment = Deployment::find($this->deploymentId);

        if (! $deployment) {
            return;
        }

        try {
            $next = $regenerator->runBatch($deployment, $this->afterId);
        } catch (PublishException $e) {
            // Otra publicación tiene el blog bloqueado: se vuelve a intentar en unos segundos.
            if ($e->errorCode === PublishException::LOCK_TIMEOUT && $this->attempts() < $this->tries) {
                $this->release(30);

                return;
            }

            $regenerator->fail($deployment, $e);

            return;
        }

        if ($next !== null) {
            static::dispatch($this->deploymentId, $next);
        }
    }

    public function failed(Throwable $e): void
    {
        $deployment = Deployment::find($this->deploymentId);

        if ($deployment && in_array($deployment->status, [Deployment::STATUS_QUEUED, Deployment::STATUS_RUNNING], true)) {
            app(BlogRegenerator::class)->fail($deployment, PublishException::make(PublishException::INCOMPLETE, null, $e));
        }
    }
}
