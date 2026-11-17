<?php

namespace App\Services;

use App\Jobs\RegenerateBlog;
use App\Models\Blog;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Activa y desactiva la cabecera y el pie compartidos (SSI) de un blog.
 *
 * Activar: la plantilla debe usar @siteHeader/@siteFooter, no puede haber otra regeneración en marcha y el
 * servidor tiene que superar la comprobación de SsiProbe. Solo entonces se guarda el cambio y se regenera el
 * blog (los artículos pasan a llevar la inclusión; el .htaccess y los fragmentos se suben antes que ellos).
 * Desactivar: se regenera con la cabecera copiada en cada página y se retiran los fragmentos y las directivas.
 */
class SsiManager
{
    public function __construct(private SsiProbe $probe, private BlogRegenerator $regenerator)
    {
    }

    /** @return array{ok:bool, message:string} */
    public function enable(Blog $blog, ?User $user = null): array
    {
        if ($blog->ssi_enabled) {
            return ['ok' => true, 'message' => 'La cabecera y el pie compartidos ya están activados.'];
        }

        if (! SitePartials::templateSupports((string) $blog->template)) {
            return ['ok' => false, 'message' => 'La plantilla «'.$blog->template.'» no está preparada: necesita @siteHeader y @siteFooter en article e index, y los parciales partials/header y partials/footer.'];
        }

        if ($error = $this->precondition($blog)) {
            return ['ok' => false, 'message' => $error];
        }

        $lock = Cache::lock("blog:{$blog->id}", (int) config('publiblog.publish.lock_ttl'));

        try {
            $lock->block((int) config('publiblog.publish.lock_wait'));
        } catch (LockTimeoutException) {
            return ['ok' => false, 'message' => 'Hay otra publicación en curso. Inténtalo en un momento.'];
        }

        try {
            $result = $this->probe->check($blog);

            if (! $result['ok']) {
                ActivityLogger::record('blog.ssi_failed', $blog, details: ['reason' => $result['message']], userId: $user?->id);

                return $result;
            }

            $blog->forceFill(['ssi_enabled' => true, 'ssi_verified_at' => now()])->save();
        } finally {
            $lock->release();
        }

        ActivityLogger::record('blog.ssi_enabled', $blog, userId: $user?->id);
        $this->regenerate($blog, $user);

        return ['ok' => true, 'message' => 'El servidor ejecuta las inclusiones. Se está regenerando el blog para usar la cabecera y el pie compartidos.'];
    }

    /** @return array{ok:bool, message:string} */
    public function disable(Blog $blog, ?User $user = null): array
    {
        if (! $blog->ssi_enabled) {
            return ['ok' => true, 'message' => 'La cabecera y el pie compartidos ya estaban desactivados.'];
        }

        if ($error = $this->precondition($blog)) {
            return ['ok' => false, 'message' => $error];
        }

        $blog->forceFill(['ssi_enabled' => false, 'ssi_verified_at' => null])->save();
        ActivityLogger::record('blog.ssi_disabled', $blog, userId: $user?->id);
        $this->regenerate($blog, $user);

        return ['ok' => true, 'message' => 'Se está regenerando el blog con la cabecera y el pie dentro de cada página.'];
    }

    private function precondition(Blog $blog): ?string
    {
        if ($this->regenerator->isRunning($blog)) {
            return 'Hay una regeneración en curso. Espera a que termine.';
        }

        if (! $blog->canPublish()) {
            return 'Antes hay que dejar la conexión del blog en estado correcto.';
        }

        return null;
    }

    private function regenerate(Blog $blog, ?User $user): void
    {
        $deployment = $this->regenerator->start($blog, $user);
        RegenerateBlog::dispatch($deployment->id);
    }
}
