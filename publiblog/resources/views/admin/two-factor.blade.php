@extends('admin.layouts.app')

@section('title', 'Código de acceso')

@section('content')
    <div class="mx-auto max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="mb-2 text-xl font-semibold">Código de acceso</h1>
        <p class="mb-6 text-sm text-gray-600">Te hemos enviado un código de 6 dígitos a {{ $email }}. Caduca en {{ config('publiblog.two_factor.ttl_minutes') }} minutos.</p>

        <form method="POST" action="{{ route('two-factor.verify') }}" class="space-y-4">
            @csrf
            <div>
                <label for="code" class="mb-1 block text-sm font-medium">Código</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-center font-mono text-lg tracking-widest focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                @error('code')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <button class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Entrar</button>
        </form>

        <form method="POST" action="{{ route('two-factor.resend') }}" class="mt-4 text-center">
            @csrf
            <button class="text-sm text-gray-600 underline hover:text-gray-900">Enviar un código nuevo</button>
        </form>
        <p class="mt-4 text-center text-sm"><a href="{{ route('login') }}" class="text-gray-500 hover:underline">Volver a entrar</a></p>
    </div>
@endsection
