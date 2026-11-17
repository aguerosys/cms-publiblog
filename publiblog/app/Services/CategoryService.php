<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Category;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Categorías de un blog. El slug es la carpeta pública (/blog/{slug}/): se fija al crearla y no cambia,
 * porque cambiarlo movería todos sus artículos. El nombre sí se puede editar.
 */
class CategoryService
{
    public function __construct(private SlugService $slugs)
    {
    }

    /**
     * Categoría de reserva del blog, si tiene una (es opcional: un blog nuevo no la tiene). Solo la usa la
     * importación para las entradas sin categoría; un artículo nuevo NO la recibe: el editor elige.
     */
    public function defaultFor(Blog $blog): ?Category
    {
        return Category::where('blog_id', $blog->id)->where('is_default', true)->first();
    }

    /** La de reserva y, si el blog no tiene, «General» (se reutiliza si ya existe, se crea si no). Para la importación. */
    public function defaultOrCreate(Blog $blog): Category
    {
        if ($default = $this->defaultFor($blog)) {
            return $default;
        }

        $existing = Category::where('blog_id', $blog->id)->where('slug', 'general')->first();

        if ($existing) {
            $existing->forceFill(['is_default' => true])->save();

            return $existing;
        }

        return Category::create([
            'blog_id' => $blog->id,
            'name' => 'General',
            'slug' => 'general',
            'is_default' => true,
        ]);
    }

    /** Slug libre para una categoría nueva a partir de un texto (nunca reservado ni repetido). */
    public function uniqueSlug(Blog $blog, string $text): string
    {
        $base = $this->slugs->normalize($text) ?: 'categoria';
        $candidate = $base;
        $n = 2;

        while ($this->slugs->isReserved($candidate) || $this->exists($blog, $candidate) || $this->collidesWithLegacyArticle($blog, $candidate)) {
            $suffix = '-'.$n++;
            $candidate = rtrim(substr($base, 0, (int) config('publiblog.slug_max_length') - strlen($suffix)), '-').$suffix;
        }

        return $candidate;
    }

    /**
     * ¿Hay un artículo del formato anterior (archivo en la raíz) cuya dirección es /blog/{slug}? La carpeta de
     * la categoría la taparía, así que ese slug no se puede usar hasta que el artículo se republique con categoría.
     */
    public function collidesWithLegacyArticle(Blog $blog, string $slug): bool
    {
        return Article::where('blog_id', $blog->id)
            ->whereNotNull('remote_path')
            ->where('remote_path', 'not like', '%/%')
            ->where('slug', $slug)
            ->exists();
    }

    public function exists(Blog $blog, string $slug, ?Category $ignore = null): bool
    {
        return Category::where('blog_id', $blog->id)
            ->where('slug', $slug)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
            ->exists();
    }

    /** @param array{name:string,slug?:?string,description?:?string,is_default?:bool} $data */
    public function create(Blog $blog, array $data): Category
    {
        $slug = $this->slugs->normalize($data['slug'] ?? '');
        $slug = $slug !== '' ? $slug : $this->uniqueSlug($blog, $data['name']);

        return DB::transaction(function () use ($blog, $data, $slug) {
            $category = Category::create([
                'blog_id' => $blog->id,
                'name' => trim($data['name']),
                'slug' => $slug,
                'description' => $this->clean($data['description'] ?? null),
                'is_default' => false,
            ]);

            if (! empty($data['is_default'])) {
                $this->makeDefault($category);
            }

            return $category;
        });
    }

    /** @param array{name:string,description?:?string,is_default?:bool} $data */
    public function update(Category $category, array $data): Category
    {
        DB::transaction(function () use ($category, $data) {
            $category->forceFill([
                'name' => trim($data['name']),
                'description' => $this->clean($data['description'] ?? null),
            ])->save();

            if (! empty($data['is_default']) && ! $category->is_default) {
                $this->makeDefault($category);
            } elseif (array_key_exists('is_default', $data) && empty($data['is_default']) && $category->is_default) {
                $category->forceFill(['is_default' => false])->save();
            }
        });

        return $category->refresh();
    }

    public function makeDefault(Category $category): void
    {
        Category::where('blog_id', $category->blog_id)->where('id', '!=', $category->id)->update(['is_default' => false]);
        $category->forceFill(['is_default' => true])->save();
    }

    /** Artículos publicados que usan la categoría. */
    public function liveCount(Category $category): int
    {
        return $category->liveArticles()->count();
    }

    /**
     * Una categoría con artículos publicados no se borra: sus URL desaparecerían.
     *
     * @throws DomainException con el motivo, listo para mostrarlo
     */
    public function delete(Category $category): void
    {
        $count = $this->liveCount($category);

        if ($count > 0) {
            throw new DomainException("«{$category->name}» tiene {$count} artículo(s) publicado(s). Cámbialos de categoría y vuelve a publicarlos antes de borrarla.");
        }

        $category->delete();
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
