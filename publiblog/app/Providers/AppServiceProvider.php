<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use App\Models\Blog;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(Login::class, fn (Login $e) => ActivityLogger::record('auth.login', subject: $e->user->email, userId: $e->user->getAuthIdentifier()));
        Event::listen(Failed::class, fn (Failed $e) => ActivityLogger::record('auth.failed', subject: (string) ($e->credentials['email'] ?? '')));

        // Plantillas de blog: @siteHeader y @siteFooter colocan la cabecera y el pie (copiados en la página
        // o, en modo SSI, como una inclusión del servidor). Son directivas, no PHP suelto de la plantilla.
        Blade::directive('siteHeader', fn () => "<?php echo app(\\App\\Services\\SitePartials::class)->render('header', \$blog, \$__pb ?? null); ?>");
        Blade::directive('siteFooter', fn () => "<?php echo app(\\App\\Services\\SitePartials::class)->render('footer', \$blog, \$__pb ?? null); ?>");

        // @templateAsset('blog.css'): dirección del archivo de assets/ de la plantilla (con su huella para la caché).
        // @structuredData: datos estructurados (JSON-LD) de la página, ya preparados y escapados por el CMS.
        Blade::directive('templateAsset', fn ($expression) => "<?php echo e(app(\\App\\Services\\TemplateAssets::class)->url(\$__pb ?? null, {$expression})); ?>");
        Blade::directive('structuredData', fn () => "<?php echo \$__ld ?? ''; ?>");

        // Selector de blog del panel: solo los blogs a los que el usuario tiene acceso (filtro global).
        View::composer('admin.layouts.app', function (\Illuminate\View\View $view) {
            $view->with('switchableBlogs', Auth::check() ? Blog::orderBy('name')->get(['id', 'name', 'domain']) : collect());
        });

        // Detrás de Cloudflare el límite de intentos del login debe usar la IP real del visitante.
        // "*" solo es aceptable si el origen únicamente recibe tráfico de Cloudflare.
        $proxies = config('publiblog.trusted_proxies');

        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
            TrustProxies::withHeaders(
                Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
            );
        }
    }
}
