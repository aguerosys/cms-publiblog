@extends('admin.layouts.app')

@php $editing = $blog->exists; @endphp

@section('title', $editing ? 'Editar blog' : 'Nuevo blog')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ $editing ? 'Editar '.$blog->name : 'Nuevo blog' }}</h1>

    <form method="POST" action="{{ $editing ? route('blogs.update', $blog) : route('blogs.store') }}" class="space-y-8">
        @csrf
        @if($editing) @method('PUT') @endif

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">Sitio</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" label="Nombre" :value="$blog->name" required />
                <x-field name="domain" label="Dominio" :value="$blog->domain" placeholder="cliente.com" hint="Siempre visible en la cabecera del panel." required />
                <x-field name="base_url" label="URL base" :value="$blog->base_url" placeholder="https://cliente.com" hint="Sin /blog al final. Los artículos salen en {URL base}/blog/slug." required />
                <x-field name="remote_dir" label="Directorio remoto" :value="$blog->remote_dir"
                         hint="La carpeta /blog/ tal como la ve el usuario SFTP. Si el usuario está confinado a /blog/, pon «/»." required />
                <x-field name="locale" label="Idioma (lang)" :value="$blog->locale" required />
                <div>
                    <label for="timezone" class="mb-1 block text-sm font-medium text-gray-700">Zona horaria <span class="text-red-500">*</span></label>
                    <select id="timezone" name="timezone" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        @foreach($timezones as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $blog->timezone) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                    @error('timezone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-field name="template" label="Plantilla" :value="$blog->template" hint="Vista Blade en resources/views/blogs/{plantilla}/ (la escribe el equipo)." required />
                <x-field name="posts_per_page" label="Artículos por página" type="number" :value="$blog->posts_per_page" required />
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-1 font-semibold">Conexión SFTP</h2>
            <p class="mb-4 text-sm text-gray-500">Un usuario SFTP por web, confinado a /blog/. El secreto se guarda cifrado y no se puede volver a leer: solo reemplazar.</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="sftp_auth" class="mb-1 block text-sm font-medium text-gray-700">Autenticación</label>
                    <select id="sftp_auth" name="sftp_auth" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option value="password" @selected(old('sftp_auth', $blog->sftp_auth) === 'password')>Contraseña</option>
                        <option value="key" @selected(old('sftp_auth', $blog->sftp_auth) === 'key')>Clave SSH privada (recomendado)</option>
                        @unless(app()->environment('production'))
                            <option value="local" @selected(old('sftp_auth', $blog->sftp_auth) === 'local')>Carpeta local (solo desarrollo)</option>
                        @endunless
                    </select>
                    @error('sftp_auth')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-field name="sftp_host" label="Servidor" :value="$blog->sftp_host" />
                <x-field name="sftp_port" label="Puerto" type="number" :value="$blog->sftp_port" />
                <x-field name="sftp_user" label="Usuario" :value="$blog->sftp_user" />
            </div>

            <div class="mt-4">
                <label for="sftp_secret" class="mb-1 block text-sm font-medium text-gray-700">
                    Contraseña o clave privada
                    @if($editing && $blog->hasSecret())<span class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">guardada — déjalo en blanco para conservarla</span>@endif
                </label>
                <textarea id="sftp_secret" name="sftp_secret" rows="3" autocomplete="off" spellcheck="false"
                          class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                @error('sftp_secret')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <x-field class="mt-4" name="sftp_passphrase" label="Frase de la clave (opcional)" type="password" />
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">Verificación</h2>
            <input type="hidden" name="verify_http" value="0">
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="verify_http" value="1" class="mt-1 rounded border-gray-300" @checked(old('verify_http', $blog->verify_http))>
                <span>Comprobar la URL pública tras publicar
                    <span class="block text-xs text-gray-500">Un artículo solo se da por publicado si su URL responde 200. Desactívalo solo en desarrollo.</span>
                </span>
            </label>
        </section>

        <div class="flex items-center gap-3">
            <button class="rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ $editing ? 'Guardar cambios' : 'Crear blog' }}</button>
            <a href="{{ $editing ? route('blogs.show', $blog) : route('blogs.index') }}" class="text-sm text-gray-600 underline">Cancelar</a>
        </div>
    </form>
@endsection
