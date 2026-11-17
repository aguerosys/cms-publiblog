<?php

namespace App\Models;

use App\Models\Scopes\BlogAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new BlogAccessScope());
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    /** Artículos PUBLICADOS en esta categoría (principal o secundaria). */
    public function liveArticles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_category')->withPivot('is_primary');
    }

    /** URL pública del listado: https://dominio.com/blog/{slug}/ */
    public function url(Blog $blog): string
    {
        return $blog->blogUrl().'/'.$this->slug.'/';
    }
}
