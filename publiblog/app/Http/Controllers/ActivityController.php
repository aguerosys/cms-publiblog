<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Blog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Registro de actividad: quién cambió qué. Cada usuario ve solo lo de sus blogs (filtro global). */
class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $blogId = $request->integer('blog') ?: null;
        $userId = $request->integer('user') ?: null;
        $group = $request->query('group');

        $entries = Activity::with(['user', 'blog'])
            ->when($blogId, fn ($q) => $q->where('blog_id', $blogId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when(in_array($group, ['article', 'blog', 'user', 'auth', 'import'], true), fn ($q) => $q->where('event', 'like', $group.'.%'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.activity.index', [
            'entries' => $entries,
            'blogs' => Blog::orderBy('name')->get(['id', 'name', 'domain']),
            // Solo el superadmin ve a todos los usuarios; los demás, quienes aparecen en su propio registro.
            'users' => $request->user()->isSuperadmin() ? User::orderBy('name')->get(['id', 'name']) : collect(),
            'filters' => ['blog' => $blogId, 'user' => $userId, 'group' => $group],
        ]);
    }
}
