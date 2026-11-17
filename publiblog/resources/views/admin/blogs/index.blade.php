@extends('admin.layouts.app')

@section('title', 'Blogs')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Blogs</h1>
        @can('create', \App\Models\Blog::class)
            <a href="{{ route('blogs.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo blog</a>
        @endcan
    </div>

    @if($blogs->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
            @can('create', \App\Models\Blog::class)
                Todavía no hay blogs. Crea el primero con sus datos SFTP.
            @else
                Todavía no tienes ningún blog asignado. Pídele a un administrador que te dé acceso.
            @endcan
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($blogs as $b)
                <a href="{{ route('blogs.show', $b) }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-indigo-300">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-semibold">{{ $b->name }}</h2>
                        @include('admin.partials.blog-status', ['blog' => $b])
                    </div>
                    <p class="mt-1 text-sm text-gray-500">{{ $b->domain }}</p>
                    <p class="mt-4 text-sm text-gray-600">{{ $b->articles_count }} {{ $b->articles_count === 1 ? 'artículo' : 'artículos' }}</p>
                    @if($b->error_articles_count)
                        <p class="mt-1 text-sm font-medium text-red-700">{{ $b->error_articles_count }} con error de publicación</p>
                    @endif
                    @if($b->last_activity_at)
                        <p class="mt-1 text-xs text-gray-400">Última actividad: {{ \Illuminate\Support\Carbon::parse($b->last_activity_at)->setTimezone($b->timezone)->format('d/m/Y H:i') }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    @if($failed->isNotEmpty())
        <section class="mt-10">
            <h2 class="mb-3 text-lg font-semibold text-red-800">Errores de publicación</h2>
            <div class="divide-y divide-red-100 rounded-lg border border-red-200 bg-white shadow-sm">
                @foreach($failed as $article)
                    <a href="{{ route('articles.edit', [$article->blog, $article]) }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm hover:bg-red-50">
                        <span>
                            <span class="font-medium">{{ $article->title ?: 'Sin título' }}</span>
                            <span class="text-gray-500">· {{ $article->blog->name }} ({{ $article->blog->domain }})</span>
                        </span>
                        <span class="text-red-700">{{ $article->lastDeployment?->error_message ?? 'Falló la última publicación.' }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
