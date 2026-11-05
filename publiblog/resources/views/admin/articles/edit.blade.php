@extends('admin.layouts.app')

@section('title', $article->title ?: 'Nuevo artículo')

@php
    $slugLocked = $article->slugIsLocked();
    $v = fn ($key, $default = '') => old($key, $default);
    $lastDeployment = $deployments->first();
    $showRetry = $article->status === 'error' || ($lastDeployment && $lastDeployment->status === 'error');
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ $article->title ?: 'Nuevo artículo' }}</h1>
            <p class="mt-1 flex items-center gap-2 text-sm text-gray-500">
                <span class="font-mono">{{ $article->public_id }}</span>
                @include('admin.partials.article-status', ['article' => $article])
                @if($article->remote_path)
                    <a href="{{ $article->url() }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">{{ $article->url() }}</a>
                @endif
            </p>
        </div>
    </div>

    @if(session('conflict'))
        <div class="mb-6 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Este artículo cambió desde que abriste el formulario. Revisa tus cambios y usa «Guardar de todos modos» para sobrescribir la versión guardada.
        </div>
    @endif

    @if($showRetry)
        <form method="POST" action="{{ route('articles.retry', [$blog, $article]) }}" class="mb-6 flex items-center gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @csrf
            <span class="flex-1">
                {{ $lastDeployment?->error_message ?? 'La última publicación falló.' }}
                El contenido está guardado; no se ha perdido nada.
            </span>
            <button class="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">Reintentar</button>
        </form>
    @endif

    <form method="POST" action="{{ route('articles.update', [$blog, $article]) }}" class="grid gap-6 lg:grid-cols-3"
          data-article-form
          data-article-id="{{ $article->id }}"
          data-upload-url="{{ route('media.store', $blog) }}"
          data-max-bytes="{{ $maxUploadBytes }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="version" value="{{ $article->version }}">

        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <x-field name="title" label="Título" :value="$json['title'] ?? ''" />

                <div class="mt-4">
                    <label for="slug" class="mb-1 block text-sm font-medium text-gray-700">Slug (URL)</label>
                    <div class="flex items-center gap-1 text-sm text-gray-500">
                        <span class="shrink-0">{{ $blog->blogUrl() }}/</span>
                        <input id="slug" name="slug" value="{{ $v('slug', $json['slug'] ?? '') }}" @readonly($slugLocked)
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 {{ $slugLocked ? 'bg-gray-100 text-gray-500' : '' }}">
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        @if($slugLocked) El slug no se puede cambiar una vez publicado. @else Se propone a partir del título y se puede editar hasta publicar. @endif
                    </p>
                    @error('slug')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="mt-4">
                    <label for="excerpt" class="mb-1 block text-sm font-medium text-gray-700">Extracto</label>
                    <textarea id="excerpt" name="excerpt" rows="2" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ $v('excerpt', $json['excerpt'] ?? '') }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Texto corto para el listado. Si queda vacío se recorta del cuerpo.</p>
                    @error('excerpt')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 px-3 py-2" data-toolbar>
                    <button type="button" class="pb-tool font-bold" data-cmd="bold" title="Negrita">B</button>
                    <button type="button" class="pb-tool italic" data-cmd="italic" title="Cursiva">I</button>
                    <span class="mx-1 h-5 w-px bg-gray-300"></span>
                    <button type="button" class="pb-tool" data-cmd="h2">H2</button>
                    <button type="button" class="pb-tool" data-cmd="h3">H3</button>
                    <span class="mx-1 h-5 w-px bg-gray-300"></span>
                    <button type="button" class="pb-tool" data-cmd="ul">• Lista</button>
                    <button type="button" class="pb-tool" data-cmd="ol">1. Lista</button>
                    <button type="button" class="pb-tool" data-cmd="quote">“ Cita</button>
                    <span class="mx-1 h-5 w-px bg-gray-300"></span>
                    <button type="button" class="pb-tool" data-cmd="link">Enlace</button>
                    <button type="button" class="pb-tool" data-cmd="image">Imagen</button>
                    <button type="button" class="pb-tool" data-cmd="alt" title="Texto alternativo de la imagen seleccionada">Alt</button>
                    <button type="button" class="pb-tool" data-cmd="table">Tabla</button>
                    <button type="button" class="pb-tool" data-cmd="addRow" title="Añadir fila">+ fila</button>
                    <button type="button" class="pb-tool" data-cmd="addCol" title="Añadir columna">+ col</button>
                    <button type="button" class="pb-tool" data-cmd="delTable" title="Borrar tabla">× tabla</button>
                    <span class="mx-1 h-5 w-px bg-gray-300"></span>
                    <button type="button" class="pb-tool" data-cmd="undo">↶</button>
                    <button type="button" class="pb-tool" data-cmd="redo">↷</button>
                    <button type="button" class="pb-tool ml-auto" data-preview-toggle>Vista previa</button>
                </div>
                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" data-body-image>

                <div data-editor-wrap>
                    <div class="pb-editor pb-prose" data-editor></div>
                </div>
                <div class="hidden p-6" data-preview>
                    <p class="mb-4 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">Vista previa del contenido. No replica el diseño final de la web del cliente.</p>
                    <h2 class="mb-4 text-3xl font-bold" data-preview-title></h2>
                    <div class="pb-prose" data-preview-body></div>
                </div>

                <input type="hidden" name="body_html" value="{{ $v('body_html', $json['body_html'] ?? '') }}">
                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500" data-upload-status>
                    El título principal (H1) lo pone la plantilla. Las imágenes se reducen automáticamente al subirlas (máx. {{ \App\Services\MediaService::humanBytes($maxUploadBytes) }}).
                </p>
            </div>
            @error('body_html')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <aside class="space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 font-semibold">Publicación</h2>
                <x-field name="published_at" label="Fecha de publicación" type="datetime-local" :value="$publishedAtInput" />
                <p class="mt-1 text-xs text-gray-500">Zona horaria del blog: {{ $blog->timezone }}.</p>
                <x-field class="mt-4" name="author_name" label="Autor" :value="$json['author']['name'] ?? ''" />

                <div class="mt-6 space-y-2">
                    @if(session('conflict'))
                        <input type="hidden" name="force" value="1">
                    @endif
                    <button type="submit" name="action" value="publish"
                            onclick="return confirm('¿Publicar en {{ $blog->domain }}?')"
                            class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        {{ session('conflict') ? 'Publicar de todos modos' : ($article->first_published_at ? 'Publicar cambios' : 'Publicar') }}
                    </button>
                    <button type="submit" name="action" value="save"
                            class="w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-50">
                        {{ session('conflict') ? 'Guardar de todos modos' : 'Guardar borrador' }}
                    </button>
                </div>
                <p class="mt-3 text-xs text-gray-500">Se publicará en <strong>{{ $blog->domain }}</strong>.</p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 font-semibold">Imagen destacada</h2>
                <input type="hidden" name="featured_media_id" value="{{ $v('featured_media_id', $featured?->id) }}">
                <img data-featured-preview src="{{ $featured?->previewUrl() }}" alt=""
                     class="mb-3 w-full rounded-md border border-gray-200 {{ $featured ? '' : 'hidden' }}">
                <input type="file" accept="image/jpeg,image/png,image/webp" data-featured-file class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm">
                <button type="button" data-featured-remove class="mt-2 text-xs text-red-600 underline {{ $featured ? '' : 'hidden' }}">Quitar imagen</button>
                <x-field class="mt-4" name="featured_alt" label="Texto alternativo" :value="$json['featured_image']['alt'] ?? ''" />
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 font-semibold">SEO</h2>
                <x-field name="meta_title" label="Meta title" :value="$json['seo']['meta_title'] ?? ''" hint="Opcional. Si falta se usa el título." />
                <div class="mt-4">
                    <label for="meta_description" class="mb-1 block text-sm font-medium text-gray-700">Meta description</label>
                    <textarea id="meta_description" name="meta_description" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ $v('meta_description', $json['seo']['meta_description'] ?? '') }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Opcional. Si falta se calcula desde el extracto.</p>
                </div>
            </div>
        </aside>
    </form>

    @if($article->exists)
        <section class="mt-10 grid gap-6 lg:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm lg:col-span-2">
                <h2 class="mb-4 font-semibold">Historial de publicaciones</h2>
                @if($deployments->isEmpty())
                    <p class="text-sm text-gray-500">Todavía no se ha publicado.</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach($deployments as $d)
                            <li class="flex items-start justify-between gap-4 py-2">
                                <span>
                                    <span class="font-medium">{{ ['publish' => 'Publicación', 'update' => 'Actualización', 'unpublish' => 'Eliminación'][$d->action] ?? $d->action }}</span>
                                    @if($d->status === 'error')<span class="text-red-600"> — {{ $d->error_message }}</span>@endif
                                </span>
                                <span class="whitespace-nowrap text-xs text-gray-500">
                                    {{ $d->status === 'ok' ? 'OK · '.$d->files_count.' archivos' : strtoupper($d->status) }} · {{ $d->created_at->setTimezone($blog->timezone)->format('d/m/Y H:i') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-lg border border-red-200 bg-white p-6 shadow-sm">
                <h2 class="mb-2 font-semibold text-red-700">Eliminar</h2>
                <p class="mb-4 text-sm text-gray-600">Retira el artículo del servidor del cliente (la URL pasará a 404). El JSON se conserva en el CMS.</p>
                <form method="POST" action="{{ route('articles.destroy', [$blog, $article]) }}"
                      onsubmit="return confirm('¿Eliminar el artículo de {{ $blog->domain }}?')">
                    @csrf
                    @method('DELETE')
                    <button class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Eliminar artículo</button>
                </form>
            </div>
        </section>
    @endif
@endsection
