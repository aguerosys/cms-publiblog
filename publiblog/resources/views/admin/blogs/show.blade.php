@extends('admin.layouts.app')

@section('title', $blog->name)

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ $blog->name }}</h1>
            <p class="text-sm text-gray-500">{{ $blog->domain }} · {{ $blog->blogUrl() }}</p>
        </div>
        <div class="flex gap-2">
            @can('update', $blog)
                <a href="{{ route('blogs.edit', $blog) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">Editar</a>
            @endcan
            <a href="{{ route('categories.index', $blog) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">Categorías</a>
            <a href="{{ route('articles.index', $blog) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Artículos ({{ $articleCount }})</a>
        </div>
    </div>

    @can('update', $blog)
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
    @else
        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Estado</h2>
                @include('admin.partials.blog-status', ['blog' => $blog])
            </div>
            <p class="mt-3 text-sm text-gray-500">Los datos de conexión los gestiona el equipo de Publiup.</p>
        </section>
    @endcan

    @can('update', $blog)
        @php $regenRunning = $regeneration && in_array($regeneration->status, ['queued', 'running'], true); @endphp
        @if($regenRunning)
            @push('head')<meta http-equiv="refresh" content="5">@endpush
        @endif

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="font-semibold">Regenerar el blog</h2>
            <p class="mt-1 text-sm text-gray-500">Vuelve a generar los artículos publicados con la plantilla actual y sube solo los archivos que cambian. Úsalo tras modificar la cabecera, el pie o el diseño.</p>

            @if($templateChanged && ! $regenRunning)
                <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    La plantilla ha cambiado desde la última regeneración: los artículos ya publicados todavía llevan el diseño anterior.
                </div>
            @endif

            @if($blog->pages_stale_at && ! $regenRunning)
                <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Hay cambios en las categorías (por ejemplo un nombre nuevo) que los artículos ya publicados todavía no llevan. Pulsa «Regenerar blog» para aplicarlos.
                </div>
            @endif

            @if($regeneration)
                @php
                    $total = max(1, (int) $regeneration->total_items);
                    $pct = $regeneration->status === 'ok' ? 100 : min(100, (int) round($regeneration->done_items / $total * 100));
                @endphp
                <div class="mt-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium">
                            @switch($regeneration->status)
                                @case('queued') En cola @break
                                @case('running') En curso @break
                                @case('ok') Terminada @break
                                @default Con errores
                            @endswitch
                            <span class="font-normal text-gray-500">· {{ $regeneration->done_items }}/{{ $regeneration->total_items }} artículos · {{ $regeneration->files_count }} archivos subidos</span>
                        </span>
                        <span class="text-xs text-gray-400">{{ ($regeneration->finished_at ?? $regeneration->created_at)->setTimezone($blog->timezone)->format('d/m/Y H:i') }}</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full {{ $regeneration->status === 'error' ? 'bg-red-500' : 'bg-indigo-600' }}" style="width: {{ $pct }}%"></div>
                    </div>
                    @if($regeneration->status === 'error')
                        <p class="mt-2 text-sm text-red-700">{{ $regeneration->error_message }}</p>
                    @endif
                    @if($regeneration->status === 'queued')
                        <p class="mt-2 text-xs text-gray-500">Lo recoge el cron de la cola en menos de un minuto.</p>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('blogs.regenerate', $blog) }}" class="mt-5 flex flex-wrap items-center gap-4"
                  onsubmit="return confirm({{ \Illuminate\Support\Js::from('Vas a regenerar los artículos publicados de «'.$blog->name.'» ('.$blog->domain.'). ¿Continuar?') }})">
                @csrf
                <button @disabled($regenRunning) class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50">Regenerar blog</button>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="force" value="1" class="rounded border-gray-300">
                    Volver a subir todo (restaurar una web perdida)
                </label>
            </form>
        </section>

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Cabecera y pie compartidos (SSI)</h2>
                <span class="rounded px-2 py-0.5 text-xs {{ $blog->ssi_enabled ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-600' }}">{{ $blog->ssi_enabled ? 'Activado' : 'Desactivado' }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                Con esta opción, cada página lleva una <em>inclusión</em> en vez de la cabecera y el pie copiados: viven en un solo archivo del servidor
                (<span class="font-mono text-xs">{{ $blog->blogUrl() }}/_inc/</span>). Cambiar la cabecera o el pie solo sube dos archivos, sin regenerar todos los artículos.
            </p>

            @if($blog->ssi_enabled)
                <p class="mt-3 text-sm text-gray-600">Comprobado en el servidor el {{ $blog->ssi_verified_at?->setTimezone($blog->timezone)->format('d/m/Y H:i') }}.</p>
                <form method="POST" action="{{ route('blogs.ssi.disable', $blog) }}" class="mt-4"
                      onsubmit="return confirm({{ \Illuminate\Support\Js::from('Se regenerará «'.$blog->name.'» ('.$blog->domain.') con la cabecera y el pie dentro de cada página. ¿Continuar?') }})">
                    @csrf
                    <button @disabled($regenRunning) class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50">Desactivar</button>
                </form>
            @elseif(! $ssiSupported)
                <p class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    La plantilla «{{ $blog->template }}» todavía no está preparada: necesita las directivas <span class="font-mono">@siteHeader</span> y <span class="font-mono">@siteFooter</span> y los parciales de cabecera y pie (ver docs/plantilla-blog.md).
                </p>
            @else
                <form method="POST" action="{{ route('blogs.ssi.enable', $blog) }}" class="mt-4"
                      onsubmit="return confirm({{ \Illuminate\Support\Js::from('Se comprobará el servidor de «'.$blog->domain.'» y, si lo permite, se regenerará el blog. ¿Continuar?') }})">
                    @csrf
                    <button @disabled($regenRunning) class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Comprobar el servidor y activar</button>
                    <span class="ml-2 text-xs text-gray-500">Antes de tocar el .htaccess real se prueba en una carpeta aparte.</span>
                </form>
            @endif
        </section>

        @php $verifyRunning = $verification && in_array($verification->status, ['queued', 'running'], true); @endphp
        @if($verifyRunning)
            @push('head')<meta http-equiv="refresh" content="5">@endpush
        @endif

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="font-semibold">Comprobar el servidor</h2>
            <p class="mt-1 text-sm text-gray-500">Lee lo publicado en el servidor del cliente y lo compara con el CMS: detecta archivos borrados o editados a mano, imágenes que faltan y reglas perdidas del .htaccess.</p>

            @if($verification)
                <p class="mt-4 text-sm">
                    <span class="font-medium">
                        @switch($verification->status)
                            @case('queued') En cola @break
                            @case('running') En curso: {{ $verification->done_items }}/{{ $verification->total_items }} @break
                            @case('ok') Última comprobación: {{ $verification->finished_at->setTimezone($blog->timezone)->format('d/m/Y H:i') }} @break
                            @default No se pudo completar
                        @endswitch
                    </span>
                    @if($verification->status === 'ok')
                        <span class="text-gray-500">· {{ $verification->done_items }} artículos ·</span>
                        <span class="{{ $differencesTotal ? 'font-medium text-amber-700' : 'text-green-700' }}">{{ $differencesTotal ? $differencesTotal.' con diferencias' : 'todo coincide' }}</span>
                    @endif
                </p>
                @if($verification->status === 'error')
                    <p class="mt-1 text-sm text-red-700">{{ $verification->error_message }}</p>
                @endif
            @endif

            @if($differences->isNotEmpty())
                <ul class="mt-4 divide-y divide-gray-100 rounded-md border border-gray-200 text-sm">
                    @foreach($differences as $a)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <a href="{{ route('articles.edit', [$blog, $a]) }}" class="hover:underline"><span class="font-mono text-xs text-gray-500">{{ $a->public_id }}</span> {{ $a->title ?: $a->slug }}</a>
                            <span class="text-amber-800">{{ \App\Models\Article::REMOTE_LABELS[$a->remote_state] ?? $a->remote_state }}</span>
                        </li>
                    @endforeach
                </ul>
                @if($differencesTotal > $differences->count())
                    <p class="mt-1 text-xs text-gray-500">Se muestran {{ $differences->count() }} de {{ $differencesTotal }}.</p>
                @endif
                <form method="POST" action="{{ route('blogs.regenerate', $blog) }}" class="mt-3"
                      onsubmit="return confirm({{ \Illuminate\Support\Js::from('Se volverán a subir a «'.$blog->name.'» ('.$blog->domain.') los '.$differencesTotal.' artículos con diferencias. ¿Continuar?') }})">
                    @csrf
                    <input type="hidden" name="only_problems" value="1">
                    <button @disabled($regenRunning) class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50">Corregir diferencias (volver a subir)</button>
                </form>
            @endif

            <form method="POST" action="{{ route('blogs.verify', $blog) }}" class="mt-5">
                @csrf
                <button @disabled($verifyRunning) class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-50 disabled:opacity-50">Comprobar ahora</button>
            </form>
        </section>

    @endcan
@endsection
