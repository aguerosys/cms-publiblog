@extends('admin.layouts.app')

@section('title', 'Usuarios')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Usuarios</h1>
        <a href="{{ route('users.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo usuario</a>
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
            <tr>
                <th class="px-4 py-3">Nombre</th>
                <th class="px-4 py-3">Correo</th>
                <th class="px-4 py-3">Rol</th>
                <th class="px-4 py-3">Blogs</th>
                <th class="px-4 py-3"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            @foreach($users as $u)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ $u->name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $u->email }}</td>
                    <td class="px-4 py-3">
                        @if($u->isSuperadmin())
                            <span class="rounded bg-indigo-50 px-2 py-0.5 text-xs text-indigo-800">Superadmin</span>
                        @else
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">Usuario de blog</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        @if($u->isSuperadmin())
                            Todos
                        @else
                            {{ $u->blogs->pluck('name')->join(', ') ?: 'Ninguno asignado' }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('users.edit', $u) }}" class="text-indigo-700 hover:underline">Editar</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
