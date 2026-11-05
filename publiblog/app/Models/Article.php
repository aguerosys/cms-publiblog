<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
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

    /** Ruta del archivo en el servidor del cliente: 000123-mi-articulo.html */
    public function fileName(): string
    {
        return $this->public_id.'-'.$this->slug.'.html';
    }

    /** Carpeta fragmentada (3 primeros dígitos del ID) en el almacenamiento del CMS. */
    public function shard(): string
    {
        return substr($this->public_id, 0, 3);
    }

    public function url(): string
    {
        return $this->blog->blogUrl().'/'.$this->slug;
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
