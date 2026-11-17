<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Models\Activity;
use App\Models\Article;
use App\Models\Blog;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ArticleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F2-6: registro de actividad (quién cambió qué). */
class ActivityLogTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $blog;
    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
        $this->blog = $this->makeBlog(['name' => 'Cliente A', 'domain' => 'a.com', 'base_url' => 'https://a.com']);
        $this->boss = $this->admin();
        $this->fakeVerification();
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function events(): array
    {
        return Activity::withoutGlobalScopes()->orderBy('id')->pluck('event')->all();
    }

    private function last(string $event): Activity
    {
        return Activity::withoutGlobalScopes()->where('event', $event)->latest('id')->firstOrFail();
    }

    public function test_creating_editing_and_publishing_are_recorded_with_the_person(): void
    {
        $this->actingAs($this->boss);
        $this->post(route('articles.store', $this->blog));
        $article = Article::sole();

        $this->put(route('articles.update', [$this->blog, $article]), $this->chooseCategory($this->blog) + [
            'action' => 'publish', 'title' => 'Mi primer artículo', 'body_html' => '<p>Hola</p>',
            'published_at' => '2026-11-10T09:00', 'version' => $article->version,
        ]);

        $this->assertContains('article.created', $this->events());
        $this->assertContains('article.saved', $this->events());
        $this->assertContains('article.published', $this->events());

        $published = $this->last('article.published');
        $this->assertSame($this->boss->id, $published->user_id);
        $this->assertSame($article->id, $published->article_id);
        $this->assertSame($this->blog->id, $published->blog_id);
        $this->assertSame('Mi primer artículo', $published->subject);
    }

    public function test_saving_records_exactly_what_changed(): void
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog, $this->boss);
        $service->save($article, ['title' => 'Primero', 'body_html' => '<p>Uno</p>', 'published_at' => '2026-11-10T09:00', 'version' => $article->version], $this->boss);

        $service->save($article, ['title' => 'Segundo', 'body_html' => '<p>Uno más largo</p>', 'published_at' => '2026-11-10T09:00', 'version' => $article->version, 'author_name' => 'Ana'], $this->boss);

        $changes = $this->last('article.saved')->details['changes'];
        $this->assertSame(['Primero', 'Segundo'], $changes['título']);
        $this->assertSame(['', 'Ana'], $changes['autor']);
        $this->assertStringContainsString('modificado', $changes['contenido']);
        $this->assertArrayNotHasKey('fecha de publicación', $changes);
        $this->assertArrayNotHasKey('extracto', $changes);
        // El cuerpo no se guarda entero en el registro.
        $this->assertStringNotContainsString('Uno más largo', json_encode($this->last('article.saved')->details));
    }

    public function test_saving_without_changes_does_not_add_noise(): void
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog, $this->boss);
        $payload = ['title' => 'Igual', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00'];

        $service->save($article, $payload + ['version' => $article->version], $this->boss);
        $before = Activity::withoutGlobalScopes()->where('event', 'article.saved')->count();
        $service->save($article, $payload + ['version' => $article->version], $this->boss);

        $this->assertSame($before, Activity::withoutGlobalScopes()->where('event', 'article.saved')->count());
    }

    public function test_publish_failures_are_recorded_with_the_error(): void
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog, $this->boss);
        $service->save($article, ['title' => 'Falla', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00', 'version' => $article->version], $this->boss);
        $this->blog->update(['status' => Blog::STATUS_HOSTKEY_CHANGED]);

        PublishArticle::dispatchSync($article->id, $this->boss->id);

        $entry = $this->last('article.publish_failed');
        $this->assertSame('blocked', $entry->details['error_code']);
        $this->assertSame($this->boss->id, $entry->user_id);
    }

    public function test_unpublish_is_recorded(): void
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog, $this->boss);
        $service->save($article, ['title' => 'Se va', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00', 'version' => $article->version], $this->boss);
        PublishArticle::dispatchSync($article->id, $this->boss->id);

        $this->actingAs($this->boss)->delete(route('articles.destroy', [$this->blog, $article]));

        $this->assertContains('article.unpublished', $this->events());
    }

    public function test_blog_changes_record_field_names_never_credentials(): void
    {
        $this->actingAs($this->boss);

        $this->put(route('blogs.update', $this->blog), [
            'name' => 'Cliente A', 'domain' => 'a.com', 'base_url' => 'https://a.com', 'remote_dir' => $this->remoteDir,
            'locale' => 'es', 'timezone' => 'Europe/Madrid', 'template' => 'demoblog', 'posts_per_page' => 5,
            'sftp_auth' => 'local', 'sftp_secret' => 'SUPER-SECRETO-123', 'verify_http' => '0',
        ])->assertRedirect();

        $entry = $this->last('blog.updated');
        $this->assertContains('posts_per_page', $entry->details['fields']);
        $this->assertStringNotContainsString('SUPER-SECRETO-123', json_encode($entry->toArray()));
    }

    public function test_user_management_is_recorded_without_passwords(): void
    {
        $this->actingAs($this->boss);

        $this->post(route('users.store'), [
            'name' => 'Luis', 'email' => 'luis@example.com', 'password' => 'clave-super-larga-1', 'role' => 'blog_user', 'blogs' => [$this->blog->id],
        ]);
        $luis = User::where('email', 'luis@example.com')->first();
        $this->put(route('users.update', $luis), ['name' => 'Luis', 'email' => 'luis@example.com', 'password' => 'otra-clave-larga-22', 'role' => 'blog_user', 'blogs' => []]);
        $this->delete(route('users.destroy', $luis));

        $created = $this->last('user.created');
        $this->assertSame('luis@example.com', $created->subject);
        $this->assertSame(['Cliente A'], $created->details['blogs']);
        $this->assertSame(['Cliente A'], $this->last('user.updated')->details['blogs']['antes']);
        $this->assertContains('user.deleted', $this->events());

        $all = json_encode(Activity::withoutGlobalScopes()->get()->toArray());
        $this->assertStringNotContainsString('clave-super-larga-1', $all);
        $this->assertStringNotContainsString('otra-clave-larga-22', $all);
    }

    public function test_logins_and_failed_attempts_are_recorded(): void
    {
        $user = User::factory()->create(['role' => 'superadmin', 'email' => 'ana@example.com']);

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'mala-clave']);
        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password']);

        $this->assertSame('ana@example.com', $this->last('auth.failed')->subject);
        $this->assertSame($user->id, $this->last('auth.login')->user_id);
        $this->assertStringNotContainsString('mala-clave', json_encode(Activity::withoutGlobalScopes()->get()->toArray()));
    }

    public function test_activity_page_lists_entries_and_filters(): void
    {
        $this->actingAs($this->boss);
        $this->post(route('articles.store', $this->blog));
        ActivityLogger::record('blog.updated', $this->blog, details: ['fields' => ['name']]);

        $this->get(route('activity.index'))->assertOk()
            ->assertSee('Artículo creado')->assertSee('Blog modificado')->assertSee($this->boss->name);

        $this->get(route('activity.index', ['group' => 'article']))->assertOk()
            ->assertSee('Artículo creado')->assertDontSee('Blog modificado');

        $this->get(route('activity.index', ['blog' => 9999]))->assertOk()->assertSee('No hay actividad');
    }

    public function test_blog_users_only_see_activity_of_their_own_blogs(): void
    {
        $other = $this->makeBlog(['name' => 'Cliente B', 'domain' => 'b.com', 'base_url' => 'https://b.com', 'remote_dir' => $this->remoteDir.'-b']);
        ActivityLogger::record('blog.updated', $this->blog, details: ['fields' => ['name']], subject: 'ACTIVIDAD-DE-A');
        ActivityLogger::record('blog.updated', $other, details: ['fields' => ['name']], subject: 'ACTIVIDAD-DE-B');
        ActivityLogger::record('user.created', subject: 'persona@interna.com');

        $user = User::factory()->create(['role' => 'blog_user']);
        $user->blogs()->attach($this->blog->id, ['role' => 'editor']);

        $this->actingAs($user)->get(route('activity.index'))->assertOk()
            ->assertSee('ACTIVIDAD-DE-A')->assertDontSee('ACTIVIDAD-DE-B')->assertDontSee('persona@interna.com');

        // El filtro por otro blog tampoco filtra nada: el alcance global manda.
        $this->get(route('activity.index', ['blog' => $other->id]))->assertDontSee('ACTIVIDAD-DE-B');
    }

    public function test_article_page_shows_its_own_activity(): void
    {
        $this->actingAs($this->boss);
        $article = app(ArticleService::class)->createDraft($this->blog, $this->boss);
        app(ArticleService::class)->save($article, ['title' => 'Con historia', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00', 'version' => $article->version], $this->boss);

        $this->get(route('articles.edit', [$this->blog, $article]))->assertOk()
            ->assertSee('Actividad del artículo')->assertSee('Artículo editado')->assertSee('Con historia');
    }

    public function test_a_logging_failure_never_breaks_the_operation(): void
    {
        Activity::creating(function () {
            throw new \RuntimeException('el registro falla');
        });

        try {
            $article = app(ArticleService::class)->createDraft($this->blog, $this->boss);
            $this->assertNotNull($article->id);
            $this->assertSame(0, Activity::withoutGlobalScopes()->count());
        } finally {
            Activity::flushEventListeners();
        }
    }

    public function test_deleting_a_user_or_blog_keeps_the_trail(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        ActivityLogger::record('auth.login', subject: $user->email, userId: $user->id);

        $user->delete();

        $this->assertSame(1, Activity::withoutGlobalScopes()->where('event', 'auth.login')->whereNull('user_id')->count());
    }
}
