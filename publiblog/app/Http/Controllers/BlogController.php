<?php

namespace App\Http\Controllers;

use App\Http\Requests\BlogRequest;
use App\Jobs\RegenerateBlog;
use App\Jobs\VerifyBlog;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Services\ActivityLogger;
use App\Services\BlogRegenerator;
use App\Services\BlogVerifier;
use App\Services\Remote\ConnectionTester;
use App\Services\SitePartials;
use App\Services\SsiManager;
use App\Services\TemplateFingerprint;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function index(): View
    {
        return view('admin.blogs.index', [
            'blogs' => Blog::withCount([
                'articles',
                'articles as error_articles_count' => fn ($q) => $q->where('status', Article::STATUS_ERROR),
            ])->withMax('deployments as last_activity_at', 'finished_at')->orderBy('name')->get(),
            // Errores de publicación pendientes de reintentar, de todos los blogs visibles para el usuario.
            'failed' => Article::with(['blog', 'lastDeployment'])
                ->where('status', Article::STATUS_ERROR)
                ->latest('updated_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.blogs.form', ['blog' => new Blog([
            'remote_dir' => '/blog/',
            'protocol' => 'sftp',
            'sftp_port' => 22,
            'sftp_auth' => 'password',
            'locale' => 'es',
            'timezone' => 'Europe/Madrid',
            'posts_per_page' => 12,
            'template' => 'demoblog',
            'verify_http' => true,
        ]), 'timezones' => DateTimeZone::listIdentifiers()]);
    }

    public function store(BlogRequest $request): RedirectResponse
    {
        $blog = Blog::create($this->attributes($request) + ['status' => Blog::STATUS_PENDING]);
        ActivityLogger::record('blog.created', $blog, details: ['domain' => $blog->domain, 'template' => $blog->template]);

        return redirect()->route('blogs.show', $blog)
            ->with('status', 'Blog creado. Prueba la conexión para guardar la huella del servidor.');
    }

    public function show(Blog $blog): View
    {
        Gate::authorize('view', $blog);

        return view('admin.blogs.show', [
            'blog' => $blog,
            'articleCount' => $blog->articles()->count(),
            'pendingFingerprint' => session('pending_fingerprint'),
            'pendingCaValid' => session('pending_ca_valid'),
            'regeneration' => Deployment::where('blog_id', $blog->id)->where('action', 'regenerate')->whereNull('article_id')->latest('id')->first(),
            'templateChanged' => TemplateFingerprint::changed($blog),
            'ssiSupported' => SitePartials::templateSupports((string) $blog->template),
            'verification' => Deployment::where('blog_id', $blog->id)->where('action', 'verify')->latest('id')->first(),
            'differences' => Article::where('blog_id', $blog->id)->whereIn('remote_state', BlogVerifier::PROBLEMS)->orderBy('public_id')->limit(50)->get(),
            'differencesTotal' => Article::where('blog_id', $blog->id)->whereIn('remote_state', BlogVerifier::PROBLEMS)->count(),
        ]);
    }

    public function edit(Blog $blog): View
    {
        return view('admin.blogs.form', ['blog' => $blog, 'timezones' => DateTimeZone::listIdentifiers()]);
    }

    public function update(BlogRequest $request, Blog $blog): RedirectResponse
    {
        $data = $this->attributes($request);

        // Las credenciales solo se pueden reemplazar, nunca leer: en blanco = conservar.
        foreach (['sftp_secret', 'sftp_passphrase'] as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }

        $connectionChanged = $data['sftp_host'] !== $blog->sftp_host
            || $data['protocol'] !== $blog->protocol
            || (int) $data['sftp_port'] !== (int) $blog->sftp_port
            || $data['sftp_auth'] !== $blog->sftp_auth
            || $data['sftp_user'] !== $blog->sftp_user
            || isset($data['sftp_secret'])
            || $data['remote_dir'] !== $blog->remote_dir;

        if ($data['sftp_host'] !== $blog->sftp_host || (int) $data['sftp_port'] !== (int) $blog->sftp_port || $data['protocol'] !== $blog->protocol) {
            $data['host_key_fingerprint'] = null; // otro servidor, puerto o protocolo: hay que confirmar la huella o el certificado de nuevo
            $data['ftps_trust'] = null;
        }

        if ($connectionChanged) {
            $data['status'] = Blog::STATUS_PENDING;
        }

        $blog->fill($data);
        // Solo los nombres de los campos: nunca el valor de una credencial.
        $changed = array_keys($blog->getDirty());
        $blog->save();

        if ($changed) {
            ActivityLogger::record('blog.updated', $blog, details: ['fields' => $changed]);
        }

        return redirect()->route('blogs.show', $blog)->with('status', 'Blog actualizado.');
    }

    public function testConnection(Blog $blog, ConnectionTester $tester): RedirectResponse
    {
        $result = $tester->test($blog);
        ActivityLogger::record('blog.connection_tested', $blog, details: ['ok' => (bool) $result['ok'], 'code' => $result['code'] ?? null]);

        if ($result['fingerprint'] && ($result['code'] === 'hostkey' || ! $blog->host_key_fingerprint)) {
            if ($result['code'] === 'hostkey') {
                $blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);
            }

            $what = $blog->isFtps() ? 'certificado' : 'huella';

            return redirect()->route('blogs.show', $blog)->with([
                'pending_fingerprint' => $result['fingerprint'],
                'pending_ca_valid' => $result['ca_valid'] ?? null,
                'status' => $result['code'] === 'hostkey'
                    ? "El {$what} del servidor ha cambiado. Si el cambio es esperado, confírmalo."
                    : "El servidor responde. Comprueba la huella del {$what} y confírmala para continuar.",
            ]);
        }

        if ($result['ok']) {
            $update = ['status' => Blog::STATUS_ACTIVE];

            // FTPS con certificado de una autoridad: una renovación cambia la huella sin ser un cambio de servidor.
            if ($blog->isFtps() && $result['fingerprint'] && $result['fingerprint'] !== $blog->host_key_fingerprint) {
                $update['host_key_fingerprint'] = $result['fingerprint'];
            }

            $blog->update($update);

            return redirect()->route('blogs.show', $blog)->with('status', $result['message']);
        }

        if ($result['code'] === 'auth') {
            $blog->update(['status' => Blog::STATUS_CONNECTION_ERROR]);
        }

        return redirect()->route('blogs.show', $blog)->with('error', $result['message']);
    }

    /** Regenera todos los artículos publicados con la plantilla actual (trabajo en cola, con progreso). */
    public function regenerate(Request $request, Blog $blog, BlogRegenerator $regenerator): RedirectResponse
    {
        if ($regenerator->isRunning($blog)) {
            return redirect()->route('blogs.show', $blog)->with('error', 'Ya hay una regeneración en curso.');
        }

        if (! $blog->canPublish()) {
            return redirect()->route('blogs.show', $blog)->with('error', 'Antes hay que dejar la conexión del blog en estado correcto.');
        }

        $deployment = $regenerator->start($blog, $request->user(), force: $request->boolean('force'), onlyProblems: $request->boolean('only_problems'));
        ActivityLogger::record('blog.regenerate_started', $blog, details: ['articles' => $deployment->total_items, 'force' => (bool) $deployment->force]);
        RegenerateBlog::dispatch($deployment->id);

        return redirect()->route('blogs.show', $blog)->with('status', $deployment->total_items === 0
            ? 'No hay artículos publicados que regenerar.'
            : 'Regeneración en cola. Verás el progreso aquí.');
    }

    /** Comparte la cabecera y el pie en un único archivo del servidor (SSI), tras comprobar que el servidor lo permite. */
    public function enableSsi(Request $request, Blog $blog, SsiManager $ssi): RedirectResponse
    {
        $result = $ssi->enable($blog, $request->user());

        return redirect()->route('blogs.show', $blog)->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function disableSsi(Request $request, Blog $blog, SsiManager $ssi): RedirectResponse
    {
        $result = $ssi->disable($blog, $request->user());

        return redirect()->route('blogs.show', $blog)->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    /** Lee el servidor del cliente y marca los artículos que no coinciden con el CMS. */
    public function verify(Request $request, Blog $blog, BlogVerifier $verifier): RedirectResponse
    {
        if ($verifier->isRunning($blog)) {
            return redirect()->route('blogs.show', $blog)->with('error', 'Ya hay una comprobación en curso.');
        }

        if (! $blog->canPublish()) {
            return redirect()->route('blogs.show', $blog)->with('error', 'Antes hay que dejar la conexión del blog en estado correcto.');
        }

        VerifyBlog::dispatch($verifier->start($blog, $request->user())->id);

        return redirect()->route('blogs.show', $blog)->with('status', 'Comprobación en cola. Verás el resultado aquí.');
    }

    /** Un administrador acepta la huella mostrada; se vuelve a comprobar antes de guardarla. */
    public function confirmFingerprint(Request $request, Blog $blog, ConnectionTester $tester): RedirectResponse
    {
        $data = $request->validate(['fingerprint' => ['required', 'string', 'regex:/^sha256:[A-Za-z0-9+\/]{43}$/']]);

        $result = $tester->test($blog, $data['fingerprint']);

        if (! $result['ok']) {
            return redirect()->route('blogs.show', $blog)->with('error', $result['message']);
        }

        // FTPS: según el certificado, de ahora en adelante se fija la huella (`pin`) o se valida con las autoridades (`ca`).
        $trust = $blog->isFtps() ? ($result['trust'] ?? 'pin') : null;

        $blog->update(['host_key_fingerprint' => $data['fingerprint'], 'ftps_trust' => $trust, 'status' => Blog::STATUS_ACTIVE]);
        ActivityLogger::record('blog.fingerprint_confirmed', $blog, details: ['fingerprint' => $data['fingerprint'], 'trust' => $trust]);

        return redirect()->route('blogs.show', $blog)->with('status', 'Huella confirmada. El blog está listo para publicar.');
    }

    private function attributes(BlogRequest $request): array
    {
        $data = $request->validated();
        $data['verify_http'] = $request->boolean('verify_http');
        $data['protocol'] = ($data['protocol'] ?? 'sftp') === 'ftps' && $data['sftp_auth'] !== 'local' ? 'ftps' : 'sftp';
        $defaultPort = $data['protocol'] === 'ftps' ? 21 : 22;
        $data['sftp_port'] = (int) ($data['sftp_port'] ?? $defaultPort) ?: $defaultPort;
        $data['sftp_host'] = $data['sftp_host'] ?? null;
        $data['sftp_user'] = $data['sftp_user'] ?? null;
        $data['remote_dir'] = $data['sftp_auth'] === 'local'
            ? rtrim($data['remote_dir'], '/\\')
            : '/'.trim($data['remote_dir'], '/').'/';
        $data['remote_dir'] = $data['remote_dir'] === '//' ? '/' : $data['remote_dir'];

        if ($data['sftp_auth'] === 'local') {
            $data['sftp_host'] = $data['sftp_user'] = null;
        }

        return $data;
    }
}
