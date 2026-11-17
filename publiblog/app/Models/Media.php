<?php

namespace App\Models;

use App\Models\Scopes\BlogAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    protected $table = 'media';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new BlogAccessScope());
    }

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** Ruta pública absoluta del sitio: /blog/img/2026/archivo.jpg */
    public function publicPath(): string
    {
        return '/'.config('publiblog.public_prefix').'/'.$this->remote_path;
    }

    /** URL protegida del CMS, usada solo dentro del editor. */
    public function previewUrl(): string
    {
        return route('media.show', [$this->blog_id, $this->id], false);
    }
}
