<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\Media;
use App\Models\User;
use App\Services\ArticleService;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F2-1: roles, asignación de blogs y aislamiento entre clientes. */
class RolesTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $a;
    private Blog $b;
    private User $anna;   // solo blog A
    private User $boss;   // superadmin
    private Article $articleA;
    private Article $articleB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();

        $this->a = $this->makeBlog(['name' => 'Cliente A', 'domain' => 'a.com', 'base_url' => 'https://a.com']);
        $this->b = $this->makeBlog(['name' => 'Cliente B', 'domain' => 'b.com', 'base_url' => 'https://b.com', 'remote_dir' => $this->remoteDir.'-b']);

        $this->boss = $this->admin();
        $this->anna = $this->blogUser($this->a);

        $this->articleA = app(ArticleService::class)->createDraft($this->a);
        $this->articleB = app(ArticleService::class)->createDraft($this->b);
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function blogUser(Blog ...$blogs): User
    {
        $user = User::factory()->create(['role' => User::ROLE_BLOG_USER]);
        foreach ($blogs as $blog) {
            $user->blogs()->attach($blog->id, ['role' => 'editor']);
        }

        return $user;
    }

    public function test_blog_user_lists_only_assigned_blogs(): void
    {
        $this->actingAs($this->anna)->get(route('blogs.index'))
            ->assertOk()->assertSee('Cliente A')->assertDontSee('Cliente B');

        $this->actingAs($this->boss)->get(route('blogs.index'))
            ->assertOk()->assertSee('Cliente A')->assertSee('Cliente B');
    }

    public function test_blog_user_cannot_reach_another_clients_blog_articles_or_media(): void
    {
        $mediaB = app(MediaService::class)->store($this->b, $this->articleB, $this->jpegUpload(800, 600));
        $this->actingAs($this->anna);

        $this->get(route('blogs.show', $this->b))->assertNotFound();
        $this->get(route('articles.index', $this->b))->assertNotFound();
        $this->post(route('articles.store', $this->b))->assertNotFound();
        $this->get(route('articles.edit', [$this->b, $this->articleB]))->assertNotFound();
        $this->put(route('articles.update', [$this->b, $this->articleB]), ['action' => 'save', 'version' => 1])->assertNotFound();
        $this->delete(route('articles.destroy', [$this->b, $this->articleB]))->assertNotFound();
        $this->post(route('articles.retry', [$this->b, $this->articleB]))->assertNotFound();
        $this->get(route('media.show', [$this->b, $mediaB]))->assertNotFound();
        $this->post(route('media.store', $this->b), ['image' => $this->jpegUpload(100, 100), 'article_id' => $this->articleB->id])->assertNotFound();
    }

    public function test_mixing_own_blog_with_a_foreign_article_id_is_rejected(): void
    {
        $this->actingAs($this->anna);

        // El blog es el suyo, pero el artículo pertenece a otro: scopeBindings + filtro global.
        $this->get(route('articles.edit', [$this->a, $this->articleB]))->assertNotFound();
        $this->post(route('media.store', $this->a), ['image' => $this->jpegUpload(100, 100), 'article_id' => $this->articleB->id])
            ->assertNotFound();
    }

    public function test_blog_user_can_work_on_assigned_blog(): void
    {
        $this->actingAs($this->anna);

        $this->get(route('articles.index', $this->a))->assertOk();
        $this->post(route('articles.store', $this->a))->assertRedirect();

        $article = Article::where('blog_id', $this->a->id)->latest('id')->first();
        $this->get(route('articles.edit', [$this->a, $article]))->assertOk();

        $this->put(route('articles.update', [$this->a, $article]), [
            'action' => 'save', 'title' => 'Hola', 'version' => $article->version,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('Hola', $article->fresh()->title);
    }

    public function test_blog_user_cannot_manage_blogs_connection_or_users(): void
    {
        $this->actingAs($this->anna);

        $this->get(route('blogs.create'))->assertForbidden();
        $this->post(route('blogs.store'), [])->assertForbidden();
        $this->get(route('blogs.edit', $this->a))->assertForbidden();
        $this->put(route('blogs.update', $this->a), [])->assertForbidden();
        $this->post(route('blogs.test-connection', $this->a))->assertForbidden();
        $this->post(route('blogs.confirm-fingerprint', $this->a), [])->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
        $this->post(route('users.store'), [])->assertForbidden();
    }

    public function test_blog_user_does_not_see_connection_details(): void
    {
        $this->a->update(['sftp_auth' => 'password', 'sftp_host' => 'sftp.secreto.net', 'sftp_user' => 'usuario-secreto']);

        $this->actingAs($this->anna)->get(route('blogs.show', $this->a))
            ->assertOk()->assertDontSee('sftp.secreto.net')->assertDontSee('usuario-secreto')
            ->assertDontSee('Probar conexión');

        $this->actingAs($this->boss)->get(route('blogs.show', $this->a))
            ->assertSee('sftp.secreto.net')->assertSee('Probar conexión');
    }

    public function test_global_scope_limits_every_tenant_model_for_blog_users_only(): void
    {
        $media = app(MediaService::class)->store($this->b, $this->articleB, $this->jpegUpload(500, 400));
        Deployment::create(['blog_id' => $this->b->id, 'article_id' => $this->articleB->id, 'action' => 'publish', 'status' => 'ok']);

        // Sin usuario (cola, cron, artisan): sin filtro.
        $this->assertSame(2, Blog::count());
        $this->assertSame(2, Article::count());

        Auth::login($this->anna);
        $this->assertSame([$this->a->id], Blog::pluck('id')->all());
        $this->assertSame([$this->articleA->id], Article::pluck('id')->all());
        $this->assertSame(0, Media::count());
        $this->assertSame(0, Deployment::count());
        $this->assertNull(Media::find($media->id));

        Auth::login($this->boss);
        $this->assertSame(2, Blog::count());
        $this->assertSame(1, Media::count());
        $this->assertSame(1, Deployment::count());
    }

    public function test_user_assigned_to_several_blogs_sees_exactly_those(): void
    {
        $both = $this->blogUser($this->a, $this->b);

        Auth::login($both);
        $this->assertSame(2, Blog::count());

        $none = $this->blogUser();
        Auth::login($none);
        $this->assertSame(0, Blog::count());
        $this->actingAs($none)->get(route('blogs.index'))->assertOk()->assertSee('ningún blog asignado');
    }

    public function test_policies_reflect_roles(): void
    {
        $this->assertTrue(Gate::forUser($this->anna)->allows('view', $this->a));
        $this->assertTrue(Gate::forUser($this->anna)->allows('write', $this->a));
        $this->assertFalse(Gate::forUser($this->anna)->allows('view', $this->b));
        $this->assertFalse(Gate::forUser($this->anna)->allows('update', $this->a));
        $this->assertFalse(Gate::forUser($this->anna)->allows('create', Blog::class));
        $this->assertFalse(Gate::forUser($this->anna)->allows('update', $this->articleB));
        $this->assertFalse(Gate::forUser($this->anna)->allows('delete', $this->articleB));

        $this->assertTrue(Gate::forUser($this->boss)->allows('update', $this->a));
        $this->assertTrue(Gate::forUser($this->boss)->allows('delete', $this->articleB));
    }

    public function test_default_role_is_not_superadmin(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'una-clave-larga-1']);

        $this->assertFalse($user->fresh()->isSuperadmin());
    }

    public function test_superadmin_creates_a_user_and_assigns_blogs(): void
    {
        $this->actingAs($this->boss)->post(route('users.store'), [
            'name' => 'Luis', 'email' => 'luis@example.com', 'password' => 'clave-muy-larga-1',
            'role' => 'blog_user', 'blogs' => [$this->b->id],
        ])->assertRedirect(route('users.index'));

        $luis = User::where('email', 'luis@example.com')->firstOrFail();
        $this->assertFalse($luis->isSuperadmin());
        $this->assertSame([$this->b->id], $luis->blogs()->pluck('blogs.id')->all());

        $this->actingAs($luis)->get(route('articles.index', $this->b))->assertOk();
        $this->actingAs($luis)->get(route('articles.index', $this->a))->assertNotFound();
    }

    public function test_user_form_validates_password_email_role_and_blogs(): void
    {
        $this->actingAs($this->boss);

        $this->post(route('users.store'), ['name' => 'X', 'email' => 'no-es-correo', 'password' => 'corta', 'role' => 'root', 'blogs' => [9999]])
            ->assertSessionHasErrors(['email', 'password', 'role', 'blogs.0']);

        $this->post(route('users.store'), ['name' => 'X', 'email' => $this->anna->email, 'password' => 'clave-muy-larga-1', 'role' => 'blog_user'])
            ->assertSessionHasErrors('email');
    }

    public function test_editing_a_user_updates_blogs_and_keeps_password_when_blank(): void
    {
        $hash = $this->anna->password;

        $this->actingAs($this->boss)->put(route('users.update', $this->anna), [
            'name' => 'Anna R.', 'email' => $this->anna->email, 'password' => '',
            'role' => 'blog_user', 'blogs' => [$this->b->id],
        ])->assertRedirect(route('users.index'));

        $anna = $this->anna->fresh();
        $this->assertSame('Anna R.', $anna->name);
        $this->assertSame($hash, $anna->password);
        $this->assertSame([$this->b->id], $anna->blogs()->pluck('blogs.id')->all());
    }

    public function test_promoting_to_superadmin_clears_assignments(): void
    {
        $this->actingAs($this->boss)->put(route('users.update', $this->anna), [
            'name' => $this->anna->name, 'email' => $this->anna->email, 'role' => 'superadmin', 'blogs' => [$this->a->id],
        ])->assertRedirect();

        $this->assertTrue($this->anna->fresh()->isSuperadmin());
        $this->assertSame(0, $this->anna->blogs()->count());
    }

    public function test_superadmin_cannot_demote_or_delete_themselves(): void
    {
        $this->actingAs($this->boss);

        $this->put(route('users.update', $this->boss), [
            'name' => $this->boss->name, 'email' => $this->boss->email, 'role' => 'blog_user',
        ])->assertSessionHas('error');
        $this->assertTrue($this->boss->fresh()->isSuperadmin());

        $this->delete(route('users.destroy', $this->boss))->assertSessionHas('error');
        $this->assertNotNull(User::find($this->boss->id));
    }

    public function test_deleting_a_user_keeps_their_articles(): void
    {
        $article = app(ArticleService::class)->createDraft($this->a, $this->anna);
        $this->assertSame($this->anna->id, $article->created_by);

        $this->actingAs($this->boss)->delete(route('users.destroy', $this->anna))->assertRedirect(route('users.index'));

        $this->assertNull(User::find($this->anna->id));
        $this->assertNotNull(Article::find($article->id));
        $this->assertNull($article->fresh()->created_by);
    }

    public function test_deleting_a_blog_removes_its_assignments(): void
    {
        $this->b->delete();

        $this->assertSame(0, \DB::table('blog_user')->where('blog_id', $this->b->id)->count());
        $this->assertSame(1, \DB::table('blog_user')->where('blog_id', $this->a->id)->count());
    }
}
