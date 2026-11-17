<?php

namespace App\Models;

use App\Models\Scopes\BlogAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea del registro de actividad. Solo se escribe; nunca se edita. */
class Activity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected $guarded = [];

    /** Etiquetas legibles de cada evento. */
    public const LABELS = [
        'article.created' => 'Artículo creado',
        'article.saved' => 'Artículo editado',
        'article.published' => 'Artículo publicado',
        'article.updated' => 'Artículo actualizado en la web',
        'article.unpublished' => 'Artículo eliminado de la web',
        'article.publish_failed' => 'Falló una publicación',
        'category.created' => 'Categoría creada',
        'category.updated' => 'Categoría modificada',
        'category.deleted' => 'Categoría borrada',
        'blog.created' => 'Blog creado',
        'blog.updated' => 'Blog modificado',
        'blog.connection_tested' => 'Conexión probada',
        'blog.fingerprint_confirmed' => 'Huella del servidor confirmada',
        'blog.regenerated' => 'Blog regenerado',
        'blog.ssi_enabled' => 'Cabecera y pie compartidos activados',
        'blog.ssi_disabled' => 'Cabecera y pie compartidos desactivados',
        'blog.ssi_failed' => 'Falló la comprobación de SSI',
        'blog.regenerate_started' => 'Regeneración iniciada',
        'blog.verified' => 'Servidor comprobado',
        'blog.media_cleaned' => 'Imágenes huérfanas limpiadas',
        'import.completed' => 'Importación terminada',
        'user.created' => 'Usuario creado',
        'user.updated' => 'Usuario modificado',
        'user.deleted' => 'Usuario eliminado',
        'auth.login' => 'Inicio de sesión',
        'auth.failed' => 'Intento de acceso fallido',
    ];

    protected static function booted(): void
    {
        // Un usuario de blog solo ve la actividad de sus blogs (las entradas sin blog, solo el superadmin).
        static::addGlobalScope(new BlogAccessScope());
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? $this->event;
    }
}
