<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\Category;
use App\Services\ActivityLogger;
use App\Services\BlogRegenerator;
use App\Services\CategoryService;
use App\Services\SlugService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Categorías de un blog. Las ve y gestiona quien puede trabajar en el blog. El slug (la carpeta pública)
 * se elige al crear y no se puede cambiar; el nombre sí.
 */
class CategoryController extends Controller
{
    public function __construct(private CategoryService $categories, private SlugService $slugs)
    {
    }

    public function index(Blog $blog): View
    {
        Gate::authorize('view', $blog);

        $list = $blog->categories()
            ->withCount(['liveArticles as published_count'])
            ->orderBy('name')
            ->get();

        return view('admin.categories.index', ['blog' => $blog, 'categories' => $list]);
    }

    public function create(Blog $blog): View
    {
        Gate::authorize('write', $blog);

        return view('admin.categories.form', ['blog' => $blog, 'category' => new Category()]);
    }

    public function store(Request $request, Blog $blog): RedirectResponse
    {
        Gate::authorize('write', $blog);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable', 'string', 'max:'.config('publiblog.slug_max_length'),
                function ($attribute, $value, $fail) use ($blog) {
                    if (blank($value)) {
                        return;
                    }

                    $slug = $this->slugs->normalize($value);

                    if (! $this->slugs->isValid($slug)) {
                        $fail('El slug solo puede llevar letras, números y guiones.');
                    } elseif ($this->slugs->isReserved($slug)) {
                        $fail("«{$slug}» es una palabra reservada.");
                    } elseif ($this->categories->exists($blog, $slug)) {
                        $fail("Ya existe una categoría con el slug «{$slug}».");
                    } elseif ($this->categories->collidesWithLegacyArticle($blog, $slug)) {
                        $fail("«{$slug}» ya es la dirección de un artículo publicado antes de las categorías. Elige otro slug.");
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:300'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $category = $this->categories->create($blog, $data + ['is_default' => $request->boolean('is_default')]);

        ActivityLogger::record('category.created', $blog, details: ['name' => $category->name, 'slug' => $category->slug], userId: $request->user()->id);

        return redirect()->route('categories.index', $blog)->with('status', "Categoría «{$category->name}» creada.");
    }

    public function edit(Blog $blog, Category $category): View
    {
        Gate::authorize('write', $blog);

        return view('admin.categories.form', ['blog' => $blog, 'category' => $category]);
    }

    public function update(Request $request, Blog $blog, Category $category, BlogRegenerator $regenerator): RedirectResponse
    {
        Gate::authorize('write', $blog);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $newName = trim($data['name']);
        $newDescription = trim((string) ($data['description'] ?? '')) ?: null;
        $nameChanged = $newName !== $category->name;
        $changed = $nameChanged || $newDescription !== $category->description;

        $this->categories->update($category, $data + ['is_default' => $request->boolean('is_default')]);

        ActivityLogger::record('category.updated', $blog, details: ['name' => $category->name, 'slug' => $category->slug], userId: $request->user()->id);

        $message = "Categoría «{$category->name}» guardada.";

        // Solo importa si la categoría ya está en páginas publicadas.
        if (! $changed || ! $category->liveArticles()->exists()) {
            return redirect()->route('categories.index', $blog)->with('status', $message);
        }

        // El menú y el listado de la categoría se actualizan ahora; los artículos son otra cosa (llevan el nombre en
        // sus migas de pan y enlaces) y se actualizan al regenerar el blog: el panel lo recuerda hasta que se haga.
        $refresh = $regenerator->refreshListings($blog);

        if ($refresh['ok']) {
            $message .= ' Los listados del blog ya están actualizados.';
        }

        if ($nameChanged || ! $refresh['ok']) {
            $blog->forceFill(['pages_stale_at' => now()])->save();
        }

        if (! $refresh['ok']) {
            $message .= " No se pudieron actualizar los listados ahora ({$refresh['message']}); se actualizarán al regenerar el blog.";
        } elseif ($nameChanged) {
            $message .= ' Falta regenerar el blog para que el nombre nuevo llegue a las páginas de los artículos ya publicados.';
        }

        return redirect()->route('categories.index', $blog)->with('status', $message);
    }

    public function destroy(Request $request, Blog $blog, Category $category): RedirectResponse
    {
        Gate::authorize('write', $blog);

        try {
            $this->categories->delete($category);
        } catch (DomainException $e) {
            return redirect()->route('categories.index', $blog)->with('error', $e->getMessage());
        }

        ActivityLogger::record('category.deleted', $blog, details: ['name' => $category->name, 'slug' => $category->slug], userId: $request->user()->id);

        return redirect()->route('categories.index', $blog)->with('status', "Categoría «{$category->name}» borrada.");
    }
}
