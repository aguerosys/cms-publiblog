<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Article;
use App\Models\Blog;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Registro de actividad (F2-6): quién hizo qué, sobre qué y cuándo.
 *
 * Reglas: nunca guarda credenciales ni contenido completo (solo qué campos cambiaron y cuánto),
 * y un fallo al escribir el registro jamás rompe la operación que se está registrando.
 */
class ActivityLogger
{
    /** @param array<string,mixed> $details */
    public static function record(string $event, ?Blog $blog = null, ?Article $article = null, array $details = [], ?string $subject = null, ?int $userId = null): void
    {
        try {
            Activity::withoutGlobalScopes()->create([
                'blog_id' => $blog?->id ?? $article?->blog_id,
                'user_id' => $userId ?? Auth::id(),
                'article_id' => $article?->id,
                'event' => $event,
                'subject' => self::cut($subject ?? $article?->title ?? $blog?->name, 200),
                'details' => $details ?: null,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Diferencias entre dos versiones de un artículo. El cuerpo solo se resume (cambió / cuántos caracteres).
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string,mixed>
     */
    public static function diffArticle(array $before, array $after): array
    {
        $fields = [
            'title' => 'título',
            'slug' => 'slug',
            'excerpt' => 'extracto',
            'seo.meta_title' => 'meta título',
            'seo.meta_description' => 'meta descripción',
            'published_at' => 'fecha de publicación',
            'author.name' => 'autor',
            'featured_image.file' => 'imagen destacada',
            'featured_image.alt' => 'texto alternativo de la portada',
        ];

        $changes = [];

        foreach ($fields as $path => $label) {
            $old = (string) data_get($before, $path, '');
            $new = (string) data_get($after, $path, '');

            if ($path === 'published_at') {
                $old = $old !== '' ? date('Y-m-d H:i', strtotime($old)) : '';
                $new = $new !== '' ? date('Y-m-d H:i', strtotime($new)) : '';
            }

            if ($old !== $new) {
                $changes[$label] = [self::cut($old, 120), self::cut($new, 120)];
            }
        }

        // La primera es la principal (da la URL): el orden también cuenta.
        $oldCategories = implode(', ', array_column($before['categories'] ?? [], 'name'));
        $newCategories = implode(', ', array_column($after['categories'] ?? [], 'name'));

        if ($oldCategories !== $newCategories) {
            $changes['categorías'] = [self::cut($oldCategories, 120), self::cut($newCategories, 120)];
        }

        $oldBody = (string) ($before['body_html'] ?? '');
        $newBody = (string) ($after['body_html'] ?? '');

        if ($oldBody !== $newBody) {
            $delta = mb_strlen(strip_tags($newBody)) - mb_strlen(strip_tags($oldBody));
            $changes['contenido'] = 'modificado ('.($delta >= 0 ? '+' : '').$delta.' caracteres de texto)';
        }

        return $changes;
    }

    private static function cut(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
