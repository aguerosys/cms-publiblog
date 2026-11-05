@extends('admin.layouts.app')

@section('title', 'Artículos · '.$blog->name)

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">Artículos de {{ $blog->name }}</h1>
        <form method="POST" action="{{ route('articles.store', $blog) }}">
            @csrf
            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo artículo</button>
        </form>
    </div>

    <div class="mb-4 flex gap-2 text-sm">
        @foreach([null => 'Todos', 'draft' => 'Borradores', 'published' => 'Publicados', 'error' => 'Con error'] as $key => $label)
            <a href="{{ route('articles.index', ['blog' => $blog] + ($key ? ['status' => $key] : [])) }}"
               class="rounded-full px-3 py-1 {{ ($status ?: null) === ($key ?: null) ? 'bg-gray-900 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-100' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if($articles->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">No hay artículos todavía.</div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">ID</th>
                        <th class="px-4 py-3">Título</th>
                        <th class="px-4 py-3">Estado</th>
                        <th class="px-4 py-3">Fecha</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($articles as $article)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $article->public_id }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('articles.edit', [$blog, $article]) }}" class="font-medium hover:underline">{{ $article->title ?: 'Sin título' }}</a>
                                @if($article->slug)<div class="text-xs text-gray-400">/blog/{{ $article->slug }}</div>@endif
                            </td>
                            <td class="px-4 py-3">@include('admin.partials.article-status', ['article' => $article])</td>
                            <td class="px-4 py-3 text-gray-500">{{ $article->published_at?->setTimezone($blog->timezone)->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($article->status === 'published')
                                    <a href="{{ $article->url() }}" target="_blank" rel="noopener" class="text-xs text-indigo-600 hover:underline">Ver</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $articles->links() }}</div>
    @endif
@endsection
