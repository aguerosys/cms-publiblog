<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

/** Un artículo se toca solo desde un usuario con acceso a su blog. */
class ArticlePolicy
{
    public function view(User $user, Article $article): bool
    {
        return $user->canAccessBlogId($article->blog_id);
    }

    public function create(User $user): bool
    {
        return true; // el acceso al blog lo comprueba BlogPolicy::write
    }

    public function update(User $user, Article $article): bool
    {
        return $user->canAccessBlogId($article->blog_id);
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->canAccessBlogId($article->blog_id);
    }
}
