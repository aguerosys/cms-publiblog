@extends('admin.layouts.app')

@section('title', 'Blogs')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Blogs</h1>
        <a href="{{ route('blogs.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo blog</a>
    </div>

    @if($blogs->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
            Todavía no hay blogs. Crea el primero con sus datos SFTP.
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
                </a>
            @endforeach
        </div>
    @endif
@endsection
