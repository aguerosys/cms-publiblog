<?php

namespace App\Models;

use App\Models\Scopes\BlogAccessScope;
use Illuminate\Database\Eloquent\Model;
use App\Services\ArticleLocator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    /** Qué encontró la última verificación del servidor. */
    public const REMOTE_LABELS = [
        'modified' => 'Distinto del publicado',
        'missing' => 'Falta en el servidor',
        'missing_media' => 'Faltan imágenes',
        'no_rule' => 'Falta su regla en el .htaccess',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BlogAccessScope());
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'remote_checked_at' => 'datetime',
            'import_publish' => 'boolean',
            'redirects' => 'array',
            'live_summary' => 'array',
            // Aunque la instancia tenga `timestamps = false` (publicar no es editar), las fechas siguen siendo fechas.
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /** Categorías con las que está PUBLICADO (la que tiene is_primary da la URL). */
    public function liveCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'article_category')->withPivot('is_primary');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function lastDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'last_deployment_id');
    }

    /** Una vez publicado, el slug ya no se puede cambiar (fase 1). */
    public function slugIsLocked(): bool
    {
        return $this->first_published_at !== null;
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->content_hash !== null && $this->content_hash !== $this->published_hash;
    }

    /** Ruta pública (relativa a /blog/) del artículo publicado: «cocina/mi-articulo». */
    public function publicPath(): string
    {
        return $this->remote_path
            ? ArticleLocator::publicPath($this->remote_path)
            : (string) $this->slug;
    }

    /** Añade una URL antigua (relativa a /blog/) que debe redirigir a la actual. */
    public function addRedirect(string $path): void
    {
        $list = $this->redirects ?? [];

        if (! in_array($path, $list, true) && ArticleLocator::isValidPath($path)) {
            $list[] = $path;
        }

        $this->redirects = array_slice(array_values($list), -ArticleLocator::MAX_REDIRECTS);
    }

    /** Carpeta fragmentada (3 primeros dígitos del ID) en el almacenamiento del CMS. */
    public function shard(): string
    {
        return substr($this->public_id, 0, 3);
    }

    public function url(): string
    {
        return $this->blog->blogUrl().'/'.$this->publicPath();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PUBLISHED => 'Publicado',
            self::STATUS_ERROR => 'Error',
            default => 'Borrador',
        };
    }
}
