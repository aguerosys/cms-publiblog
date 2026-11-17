<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Alta de usuarios y asignación de blogs (solo superadmin, ver rutas). */
class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::with('blogs')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => new User(['role' => User::ROLE_BLOG_USER]), 'blogs' => Blog::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
        ]);

        $this->syncBlogs($user, $data);
        ActivityLogger::record('user.created', details: ['role' => $user->role, 'blogs' => $user->blogs()->pluck('blogs.name')->all()], subject: $user->email);

        return redirect()->route('users.index')->with('status', "Usuario «{$user->name}» creado.");
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', ['user' => $user, 'blogs' => Blog::orderBy('name')->get()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && $data['role'] !== User::ROLE_SUPERADMIN) {
            return back()->withInput()->with('error', 'No puedes quitarte a ti mismo el rol de superadmin.');
        }

        $attributes = ['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role']];

        if (filled($data['password'] ?? null)) {
            $attributes['password'] = $data['password'];
        }

        $user->fill($attributes);
        $changed = array_keys($user->getDirty());
        $user->save();

        $before = $user->blogs()->pluck('blogs.name')->sort()->values()->all();
        $this->syncBlogs($user, $data);
        $after = $user->blogs()->pluck('blogs.name')->sort()->values()->all();

        ActivityLogger::record('user.updated', details: array_filter([
            'fields' => $changed, // nombres de campo; nunca la contraseña
            'blogs' => $before !== $after ? ['antes' => $before, 'después' => $after] : null,
        ]), subject: $user->email);

        return redirect()->route('users.index')->with('status', "Usuario «{$user->name}» actualizado.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'No puedes eliminar tu propio usuario.');
        }

        // Sus artículos se conservan: created_by/updated_by pasan a null y el historial queda.
        ActivityLogger::record('user.deleted', details: ['role' => $user->role], subject: $user->email);
        $user->delete();

        return redirect()->route('users.index')->with('status', 'Usuario eliminado.');
    }

    private function validated(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:200'],
            'role' => ['required', Rule::in([User::ROLE_SUPERADMIN, User::ROLE_BLOG_USER])],
            'blogs' => ['nullable', 'array'],
            'blogs.*' => ['integer', Rule::exists('blogs', 'id')],
        ], [
            'password.min' => 'La contraseña debe tener al menos 12 caracteres.',
            'password.required' => 'La contraseña es obligatoria.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
        ]);
    }

    /** Un superadmin ve todos los blogs: no necesita asignaciones. */
    private function syncBlogs(User $user, array $data): void
    {
        $ids = $user->isSuperadmin() ? [] : array_map('intval', $data['blogs'] ?? []);

        $user->blogs()->sync(array_fill_keys($ids, ['role' => 'editor']));
        $user->forgetAccessibleBlogs();
    }
}
