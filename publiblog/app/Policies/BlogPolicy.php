<?php

namespace App\Policies;

use App\Models\Blog;
use App\Models\User;

/** Los blogs (y su conexión SFTP) los gestiona solo el superadmin; los blog_user solo los ven. */
class BlogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Blog $blog): bool
    {
        return $user->canAccessBlog($blog);
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Blog $blog): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, Blog $blog): bool
    {
        return $user->isSuperadmin();
    }

    /** Escribir artículos y medios en el blog. */
    public function write(User $user, Blog $blog): bool
    {
        return $user->canAccessBlog($blog);
    }
}
