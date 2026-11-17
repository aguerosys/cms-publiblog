@extends('admin.layouts.app')

@php
    $editing = $user->exists;
    $assigned = collect(old('blogs', $user->exists ? $user->blogs->pluck('id')->all() : []))->map(fn ($id) => (int) $id)->all();
    $role = old('role', $user->role);
@endphp

@section('title', $editing ? 'Editar usuario' : 'Nuevo usuario')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ $editing ? 'Editar '.$user->name : 'Nuevo usuario' }}</h1>

    <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="max-w-2xl space-y-8">
        @csrf
        @if($editing) @method('PUT') @endif

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" label="Nombre" :value="$user->name" required />
                <x-field name="email" label="Correo" type="email" :value="$user->email" required />
                <x-field name="password" label="Contraseña" type="password"
                         :hint="$editing ? 'Déjala en blanco para conservar la actual. Mínimo 12 caracteres.' : 'Mínimo 12 caracteres.'"
                         :required="! $editing" />
                <div>
                    <label for="role" class="mb-1 block text-sm font-medium text-gray-700">Rol</label>
                    <select id="role" name="role" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option value="blog_user" @selected($role === 'blog_user')>Usuario de blog (solo sus blogs)</option>
                        <option value="superadmin" @selected($role === 'superadmin')>Superadmin (todo)</option>
                    </select>
                    @error('role')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-1 font-semibold">Blogs asignados</h2>
            <p class="mb-4 text-sm text-gray-500">Un usuario de blog solo ve y edita estos blogs. Un superadmin ve todos, sin asignación.</p>

            @forelse($blogs as $b)
                <label class="flex items-center gap-2 py-1 text-sm">
                    <input type="checkbox" name="blogs[]" value="{{ $b->id }}" @checked(in_array($b->id, $assigned, true))>
                    <span class="font-medium">{{ $b->name }}</span>
                    <span class="text-gray-500">{{ $b->domain }}</span>
                </label>
            @empty
                <p class="text-sm text-gray-500">Todavía no hay blogs.</p>
            @endforelse
            @error('blogs.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </section>

        <div class="flex items-center gap-3">
            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar</button>
            <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:underline">Cancelar</a>
        </div>
    </form>

    @if($editing && ! $user->is(auth()->user()))
        <form method="POST" action="{{ route('users.destroy', $user) }}" class="mt-10 max-w-2xl"
              onsubmit="return confirm('¿Eliminar a {{ e($user->name) }}? Sus artículos se conservan.')">
            @csrf @method('DELETE')
            <button class="text-sm text-red-700 hover:underline">Eliminar usuario</button>
        </form>
    @endif
@endsection
