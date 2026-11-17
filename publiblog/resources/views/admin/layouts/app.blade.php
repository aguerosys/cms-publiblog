<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Panel') · Publiblog</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
<header class="border-b border-gray-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        <div class="flex items-center gap-4">
            <a href="{{ route('blogs.index') }}" class="text-lg font-bold tracking-tight">Publiblog</a>
            @auth
                <a href="{{ route('activity.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Actividad</a>
            @endauth
            @if(auth()->user()?->isSuperadmin())
                <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Usuarios</a>
            @endif
            @isset($blog)
                @if($blog->exists)
                    {{-- El blog y su dominio siempre visibles: evita publicar en el blog equivocado. --}}
                    <a href="{{ route('blogs.show', $blog) }}" class="flex items-center gap-2 rounded-md bg-indigo-50 px-3 py-1.5 text-sm text-indigo-900 ring-1 ring-indigo-200 hover:bg-indigo-100">
                        <span class="font-semibold">{{ $blog->name }}</span>
                        <span class="text-indigo-700">{{ $blog->domain }}</span>
                    </a>
                    <a href="{{ route('articles.index', $blog) }}" class="text-sm text-gray-600 hover:text-gray-900">Artículos</a>
                    @if($switchableBlogs->count() > 1)
                        <select aria-label="Cambiar de blog" onchange="if (this.value) location.href = this.value"
                                class="rounded-md border border-gray-300 bg-white py-1 pl-2 pr-7 text-sm text-gray-700">
                            <option value="">Cambiar de blog…</option>
                            @foreach($switchableBlogs as $other)
                                @if($other->id !== $blog->id)
                                    <option value="{{ route('articles.index', $other) }}">{{ $other->name }} · {{ $other->domain }}</option>
                                @endif
                            @endforeach
                        </select>
                    @endif
                @endif
            @endisset
        </div>
        @auth
            <form method="POST" action="{{ route('logout') }}" class="flex items-center gap-3 text-sm">
                @csrf
                <span class="text-gray-500">{{ auth()->user()->name }}</span>
                <button class="text-gray-600 underline hover:text-gray-900">Salir</button>
            </form>
        @endauth
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-8">
    @if(session('status'))
        <div class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if($errors->any() && ! $errors->has('email') && ! request()->routeIs('login'))
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Revisa los campos marcados.
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
