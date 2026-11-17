@extends('admin.layouts.app')

@section('title', 'Actividad')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Actividad</h1>

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 text-sm">
        <label>
            <span class="mb-1 block text-xs text-gray-500">Blog</span>
            <select name="blog" class="rounded-md border border-gray-300 bg-white px-2 py-1.5">
                <option value="">Todos</option>
                @foreach($blogs as $b)
                    <option value="{{ $b->id }}" @selected($filters['blog'] === $b->id)>{{ $b->name }} · {{ $b->domain }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs text-gray-500">Tipo</span>
            <select name="group" class="rounded-md border border-gray-300 bg-white px-2 py-1.5">
                <option value="">Todo</option>
                @foreach(['article' => 'Artículos', 'blog' => 'Blogs', 'import' => 'Importaciones', 'user' => 'Usuarios', 'auth' => 'Accesos'] as $key => $label)
                    <option value="{{ $key }}" @selected($filters['group'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if($users->isNotEmpty())
            <label>
                <span class="mb-1 block text-xs text-gray-500">Persona</span>
                <select name="user" class="rounded-md border border-gray-300 bg-white px-2 py-1.5">
                    <option value="">Todas</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" @selected($filters['user'] === $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <button class="rounded-md border border-gray-300 bg-white px-3 py-1.5 hover:bg-gray-50">Filtrar</button>
    </form>

    @if($entries->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">No hay actividad registrada.</div>
    @else
        <div class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white shadow-sm">
            @foreach($entries as $entry)
                <div class="px-4 py-3 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span>
                            <span class="font-medium {{ $entry->event === 'article.publish_failed' || $entry->event === 'auth.failed' ? 'text-red-700' : '' }}">{{ $entry->label() }}</span>
                            @if($entry->subject)<span class="text-gray-700"> · {{ $entry->subject }}</span>@endif
                            @if($entry->blog)<span class="text-gray-400"> · {{ $entry->blog->name }} ({{ $entry->blog->domain }})</span>@endif
                        </span>
                        <span class="whitespace-nowrap text-xs text-gray-500">
                            {{ $entry->user?->name ?? 'Sistema' }} · {{ $entry->created_at->setTimezone($entry->blog?->timezone ?? config('app.timezone'))->format('d/m/Y H:i') }}
                        </span>
                    </div>
                    @include('admin.partials.activity-details', ['entry' => $entry])
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $entries->links() }}</div>
    @endif
@endsection
