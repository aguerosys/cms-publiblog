@extends('admin.layouts.app')

@section('title', 'Categorías · '.$blog->name)

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Categorías</h1>
            <p class="text-sm text-gray-500">{{ $blog->name }} · {{ $blog->domain }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('blogs.show', $blog) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">Volver al blog</a>
            <a href="{{ route('categories.create', $blog) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva categoría</a>
        </div>
    </div>

    <p class="mb-4 text-sm text-gray-600">
        Cada categoría tiene su carpeta en la web (<span class="font-mono">{{ $blog->blogUrl() }}/<em>carpeta</em>/</span>) con su propio listado.
        La <strong>categoría principal</strong> de un artículo forma parte de su dirección; las demás solo lo añaden a sus listados.
    </p>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
            <tr>
                <th class="px-4 py-3">Nombre</th>
                <th class="px-4 py-3">Carpeta (slug)</th>
                <th class="px-4 py-3">Publicados</th>
                <th class="px-4 py-3"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            @foreach($categories as $category)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        {{ $category->name }}
                        @if($category->is_default)<span class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-600">de reserva</span>@endif
                        @if($category->description)<div class="text-xs font-normal text-gray-500">{{ $category->description }}</div>@endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $category->slug }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $category->published_count }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('categories.edit', [$blog, $category]) }}" class="text-indigo-700 hover:underline">Editar</a>
                        @if($category->published_count === 0)
                            <form method="POST" action="{{ route('categories.destroy', [$blog, $category]) }}" class="ml-3 inline"
                                  onsubmit="return confirm({{ \Illuminate\Support\Js::from('¿Borrar la categoría «'.$category->name.'»?') }})">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-700 hover:underline">Borrar</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            @if($categories->isEmpty())
                <tr>
                    <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                        Este blog aún no tiene categorías. Crea las que necesite el cliente: sin al menos una no se puede publicar ningún artículo.
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
@endsection
