@extends('admin.layouts.app')

@section('title', $category->exists ? 'Editar categoría' : 'Nueva categoría')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">{{ $category->exists ? 'Editar categoría' : 'Nueva categoría' }}</h1>
        <p class="text-sm text-gray-500">{{ $blog->name }} · {{ $blog->domain }}</p>
    </div>

    <form method="POST"
          action="{{ $category->exists ? route('categories.update', [$blog, $category]) : route('categories.store', $blog) }}"
          class="max-w-xl space-y-4 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        @csrf
        @if($category->exists) @method('PUT') @endif

        <x-field name="name" label="Nombre" :value="$category->name" required />

        @if($category->exists)
            <div>
                <span class="mb-1 block text-sm font-medium text-gray-700">Carpeta (slug)</span>
                <p class="font-mono text-sm text-gray-600">{{ $blog->blogUrl() }}/{{ $category->slug }}/</p>
                <p class="mt-1 text-xs text-gray-500">La carpeta no se puede cambiar: es parte de la dirección de todos sus artículos.</p>
            </div>
        @else
            <x-field name="slug" label="Carpeta (slug)" :hint="'Opcional. Si lo dejas vacío se propone a partir del nombre. Después no se puede cambiar.'" placeholder="recetas" />
        @endif

        <div>
            <label for="description" class="mb-1 block text-sm font-medium text-gray-700">Descripción</label>
            <textarea id="description" name="description" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ old('description', $category->description) }}</textarea>
            <p class="mt-1 text-xs text-gray-500">Opcional. Se usa como descripción (SEO) del listado de la categoría.</p>
            @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $category->is_default))>
                Categoría de reserva (opcional)
            </label>
            <p class="mt-1 text-xs text-gray-500">Solo la usa la importación de WordPress para las entradas que no tienen categoría. Los artículos nuevos <strong>no</strong> la reciben: quien edita elige siempre la categoría.</p>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar</button>
            <a href="{{ route('categories.index', $blog) }}" class="text-sm text-gray-600 hover:underline">Cancelar</a>
        </div>
    </form>
@endsection
