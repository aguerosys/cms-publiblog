@extends('admin.layouts.app')

@section('title', $blog->name)

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ $blog->name }}</h1>
            <p class="text-sm text-gray-500">{{ $blog->domain }} · {{ $blog->blogUrl() }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('blogs.edit', $blog) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">Editar</a>
            <a href="{{ route('articles.index', $blog) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Artículos ({{ $articleCount }})</a>
        </div>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold">Conexión</h2>
            @include('admin.partials.blog-status', ['blog' => $blog])
        </div>

        <dl class="mt-4 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
            @if($blog->isLocal())
                <div><dt class="text-gray-500">Transporte</dt><dd>Carpeta local (desarrollo)</dd></div>
                <div><dt class="text-gray-500">Carpeta</dt><dd class="break-all font-mono text-xs">{{ $blog->remote_dir }}</dd></div>
            @else
                <div><dt class="text-gray-500">Servidor</dt><dd>{{ $blog->sftp_host }}:{{ $blog->sftp_port }}</dd></div>
                <div><dt class="text-gray-500">Usuario</dt><dd>{{ $blog->sftp_user }}</dd></div>
                <div><dt class="text-gray-500">Autenticación</dt><dd>{{ $blog->sftp_auth === 'key' ? 'Clave SSH' : 'Contraseña' }} <span class="text-gray-400">(guardada, cifrada)</span></dd></div>
                <div><dt class="text-gray-500">Directorio remoto</dt><dd class="font-mono text-xs">{{ $blog->remote_dir }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500">Huella del servidor</dt>
                    <dd class="break-all font-mono text-xs">{{ $blog->host_key_fingerprint ?? 'Sin confirmar todavía' }}</dd></div>
            @endif
        </dl>

        @if($pendingFingerprint)
            <div class="mt-6 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm">
                <p class="font-medium text-amber-900">Huella del servidor</p>
                <p class="mt-1 break-all font-mono text-xs text-amber-900">{{ $pendingFingerprint }}</p>
                <p class="mt-2 text-amber-800">Compárala con la que te dé quien administra el servidor antes de confirmar. Si no coincide, no la aceptes.</p>
                <form method="POST" action="{{ route('blogs.confirm-fingerprint', $blog) }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="fingerprint" value="{{ $pendingFingerprint }}">
                    <button class="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">Confirmar huella y conectar</button>
                </form>
            </div>
        @endif

        <form method="POST" action="{{ route('blogs.test-connection', $blog) }}" class="mt-6">
            @csrf
            <button class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-50">Probar conexión</button>
            <span class="ml-2 text-xs text-gray-500">Conecta, comprueba la huella y escribe y borra un archivo de prueba en la carpeta remota.</span>
        </form>
    </section>
@endsection
