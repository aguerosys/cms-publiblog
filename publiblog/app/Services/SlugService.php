<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Blog;
use Illuminate\Support\Str;

class SlugService
{
    /** Normaliza texto libre a slug: transliteración, minúsculas, a-z 0-9 y guiones. */
    public function normalize(?string $text): string
    {
        $slug = Str::slug((string) $text, '-', 'es');
        $max = (int) config('publiblog.slug_max_length');

        if (strlen($slug) > $max) {
            $slug = rtrim(substr($slug, 0, $max), '-');
        }

        return $slug;
    }

    public function isReserved(string $slug): bool
    {
        return in_array($slug, config('publiblog.reserved_slugs'), true);
    }

    /** Un slug válido para construir rutas y reglas: nunca texto sin validar. */
    public function isValid(string $slug): bool
    {
        return $slug !== ''
            && strlen($slug) <= (int) config('publiblog.slug_max_length')
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    public function isTaken(Blog $blog, string $slug, ?Article $ignore = null): bool
    {
        return Article::withTrashed()
            ->where('blog_id', $blog->id)
            ->where('slug', $slug)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
            ->exists();
    }

    /** Genera un slug único a partir de un texto (para títulos sin slug explícito). */
    public function unique(Blog $blog, string $text, ?Article $ignore = null): string
    {
        $base = $this->normalize($text);

        if ($base === '') {
            $base = 'articulo';
        }

        $candidate = $base;
        $n = 2;

        while ($this->isReserved($candidate) || $this->isTaken($blog, $candidate, $ignore)) {
            $suffix = '-'.$n++;
            $candidate = rtrim(substr($base, 0, (int) config('publiblog.slug_max_length') - strlen($suffix)), '-').$suffix;
        }

        return $candidate;
    }
}
