<?php

namespace App\Http\Controllers;

use App\Http\Requests\BlogRequest;
use App\Models\Blog;
use App\Services\Remote\ConnectionTester;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function index(): View
    {
        return view('admin.blogs.index', [
            'blogs' => Blog::withCount('articles')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.blogs.form', ['blog' => new Blog([
            'remote_dir' => '/blog/',
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

        return redirect()->route('blogs.show', $blog)
            ->with('status', 'Blog creado. Prueba la conexión para guardar la huella del servidor.');
    }

    public function show(Blog $blog): View
    {
        return view('admin.blogs.show', [
            'blog' => $blog,
            'articleCount' => $blog->articles()->count(),
            'pendingFingerprint' => session('pending_fingerprint'),
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
            || (int) $data['sftp_port'] !== (int) $blog->sftp_port
            || $data['sftp_auth'] !== $blog->sftp_auth
            || $data['sftp_user'] !== $blog->sftp_user
            || isset($data['sftp_secret'])
            || $data['remote_dir'] !== $blog->remote_dir;

        if ($data['sftp_host'] !== $blog->sftp_host || (int) $data['sftp_port'] !== (int) $blog->sftp_port) {
            $data['host_key_fingerprint'] = null;
        }

        if ($connectionChanged) {
            $data['status'] = Blog::STATUS_PENDING;
        }

        $blog->update($data);

        return redirect()->route('blogs.show', $blog)->with('status', 'Blog actualizado.');
    }

    public function testConnection(Blog $blog, ConnectionTester $tester): RedirectResponse
    {
        $result = $tester->test($blog);

        if ($result['fingerprint'] && ($result['code'] === 'hostkey' || ! $blog->host_key_fingerprint)) {
            if ($result['code'] === 'hostkey') {
                $blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);
            }

            return redirect()->route('blogs.show', $blog)->with([
                'pending_fingerprint' => $result['fingerprint'],
                'status' => $result['code'] === 'hostkey'
                    ? 'La huella del servidor ha cambiado. Si el cambio es esperado, confírmala.'
                    : 'El servidor responde. Comprueba la huella y confírmala para continuar.',
            ]);
        }

        if ($result['ok']) {
            $blog->update(['status' => Blog::STATUS_ACTIVE]);

            return redirect()->route('blogs.show', $blog)->with('status', $result['message']);
        }

        if ($result['code'] === 'auth') {
            $blog->update(['status' => Blog::STATUS_CONNECTION_ERROR]);
        }

        return redirect()->route('blogs.show', $blog)->with('error', $result['message']);
    }

    /** Un administrador acepta la huella mostrada; se vuelve a comprobar antes de guardarla. */
    public function confirmFingerprint(Request $request, Blog $blog, ConnectionTester $tester): RedirectResponse
    {
        $data = $request->validate(['fingerprint' => ['required', 'string', 'regex:/^sha256:[A-Za-z0-9+\/]{43}$/']]);

        $result = $tester->test($blog, $data['fingerprint']);

        if (! $result['ok']) {
            return redirect()->route('blogs.show', $blog)->with('error', $result['message']);
        }

        $blog->update(['host_key_fingerprint' => $data['fingerprint'], 'status' => Blog::STATUS_ACTIVE]);

        return redirect()->route('blogs.show', $blog)->with('status', 'Huella confirmada. El blog está listo para publicar.');
    }

    private function attributes(BlogRequest $request): array
    {
        $data = $request->validated();
        $data['verify_http'] = $request->boolean('verify_http');
        $data['sftp_port'] = (int) ($data['sftp_port'] ?? 22) ?: 22;
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
