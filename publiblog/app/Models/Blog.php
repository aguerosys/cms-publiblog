<?php

namespace App\Models;

use App\Models\Scopes\BlogAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blog extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONNECTION_ERROR = 'connection_error';
    public const STATUS_HOSTKEY_CHANGED = 'hostkey_changed';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new BlogAccessScope('id'));
    }

    /** Las credenciales nunca salen del modelo hacia vistas, JSON ni logs. */
    protected $hidden = ['sftp_secret', 'sftp_passphrase'];

    protected function casts(): array
    {
        return [
            'sftp_secret' => 'encrypted',
            'sftp_passphrase' => 'encrypted',
            'verify_http' => 'boolean',
            'ssi_enabled' => 'boolean',
            'ssi_verified_at' => 'datetime',
            'pages_stale_at' => 'datetime',
            'sftp_port' => 'integer',
            'posts_per_page' => 'integer',
            'template_version' => 'integer',
            'last_public_id' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function isLocal(): bool
    {
        return $this->sftp_auth === 'local';
    }

    /** ¿Se conecta por FTPS (FTP con TLS) en vez de SFTP? */
    public function isFtps(): bool
    {
        return ! $this->isLocal() && $this->protocol === 'ftps';
    }

    public function protocolLabel(): string
    {
        return $this->isFtps() ? 'FTPS (FTP con TLS)' : 'SFTP';
    }

    public function hasSecret(): bool
    {
        return ! empty($this->getRawOriginal('sftp_secret'));
    }

    public function canPublish(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_CONNECTION_ERROR], true);
    }

    /** URL pública de la carpeta del blog: https://dominio.com/blog */
    public function blogUrl(): string
    {
        return rtrim($this->base_url, '/').'/'.config('publiblog.public_prefix');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Conectado',
            self::STATUS_CONNECTION_ERROR => 'Error de conexión',
            self::STATUS_HOSTKEY_CHANGED => 'Huella del servidor cambiada',
            default => 'Pendiente de probar',
        };
    }
}
