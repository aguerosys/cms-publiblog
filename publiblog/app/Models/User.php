<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'two_factor_code'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_BLOG_USER = 'blog_user';

    /** @var list<int>|null caché por instancia de los blogs asignados */
    private ?array $blogIds = null;

    public function isSuperadmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function blogs(): BelongsToMany
    {
        return $this->belongsToMany(Blog::class)->withPivot('role')->withTimestamps();
    }

    /**
     * IDs de los blogs asignados. Se lee de la tabla pivote directamente (no del modelo Blog)
     * porque el filtro global de Blog depende de esta misma lista.
     *
     * @return list<int>
     */
    public function accessibleBlogIds(): array
    {
        return $this->blogIds ??= DB::table('blog_user')
            ->where('user_id', $this->getKey())
            ->pluck('blog_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function forgetAccessibleBlogs(): void
    {
        $this->blogIds = null;
    }

    public function canAccessBlogId(int $blogId): bool
    {
        return $this->isSuperadmin() || in_array($blogId, $this->accessibleBlogIds(), true);
    }

    public function canAccessBlog(Blog $blog): bool
    {
        return $this->canAccessBlogId((int) $blog->getKey());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_expires_at' => 'datetime',
        ];
    }
}
