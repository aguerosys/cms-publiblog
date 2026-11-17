<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\User;
use App\Services\ArticleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F2-2: selector de blog con dominio, errores de publicación visibles y confirmación al publicar. */
class PanelF2Test extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $a;
    private Blog $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();

        $this->a = $this->makeBlog(['name' => 'Cliente A', 'domain' => 'a.com', 'base_url' => 'https://a.com']);
        $this->b = $this->makeBlog(['name' => 'Cliente B', 'domain' => 'b.com', 'base_url' => 'https://b.com', 'remote_dir' => $this->remoteDir.'-b']);
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function failedArticle(Blog $blog, string $title, string $message): Article
    {
        $article = app(ArticleService::class)->createDraft($blog);
        $deployment = Deployment::create([
            'blog_id' => $blog->id, 'article_id' => $article->id, 'action' => 'publish',
            'status' => 'error', 'error_code' => 'connect', 'error_message' => $message, 'finished_at' => now(),
        ]);
        $article->forceFill(['title' => $title, 'status' => Article::STATUS_ERROR, 'last_deployment_id' => $deployment->id])->save();

        return $article;
    }

    public function test_dashboard_lists_publication_errors_with_blog_and_domain(): void
    {
        $this->failedArticle($this->a, 'Receta fallida', 'No se pudo conectar con el servidor.');

        $this->actingAs($this->admin())->get(route('blogs.index'))
            ->assertOk()
            ->assertSee('Errores de publicación')
            ->assertSee('Receta fallida')
            ->assertSee('Cliente A')
            ->assertSee('a.com')
            ->assertSee('No se pudo conectar con el servidor.')
            ->assertSee('1 con error de publicación');
    }

    public function test_dashboard_has_no_error_section_when_everything_is_fine(): void
    {
        $this->actingAs($this->admin())->get(route('blogs.index'))
            ->assertOk()->assertDontSee('Errores de publicación');
    }

    public function test_blog_user_only_sees_errors_of_their_own_blogs(): void
    {
        $this->failedArticle($this->a, 'Error propio', 'Fallo A');
        $this->failedArticle($this->b, 'Error ajeno', 'Fallo B');

        $user = User::factory()->create(['role' => 'blog_user']);
        $user->blogs()->attach($this->a->id, ['role' => 'editor']);

        $this->actingAs($user)->get(route('blogs.index'))
            ->assertOk()->assertSee('Error propio')->assertDontSee('Error ajeno')->assertDontSee('Fallo B');
    }

    public function test_blog_switcher_lists_other_accessible_blogs_with_domain(): void
    {
        $article = app(ArticleService::class)->createDraft($this->a);

        $this->actingAs($this->admin())->get(route('articles.edit', [$this->a, $article]))
            ->assertOk()
            ->assertSee('Cambiar de blog')
            ->assertSee('Cliente B · b.com')
            ->assertSee('a.com'); // el blog actual siempre visible en la cabecera
    }

    public function test_blog_user_with_one_blog_gets_no_switcher_and_never_sees_other_blogs(): void
    {
        $user = User::factory()->create(['role' => 'blog_user']);
        $user->blogs()->attach($this->a->id, ['role' => 'editor']);

        $this->actingAs($user)->get(route('articles.index', $this->a))
            ->assertOk()->assertDontSee('Cambiar de blog')->assertDontSee('b.com');
    }

    public function test_publish_and_delete_ask_for_confirmation_naming_the_blog_and_domain(): void
    {
        $article = app(ArticleService::class)->createDraft($this->a);

        $html = $this->actingAs($this->admin())->get(route('articles.edit', [$this->a, $article]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/confirm\(.*Cliente A.*a\.com.*público/su', $html);
        $this->assertMatchesRegularExpression('/confirm\(.*eliminar.*Cliente A.*a\.com/su', $html);
    }

    public function test_confirmation_text_is_safe_for_blog_names_with_quotes(): void
    {
        $this->a->update(['name' => "Cliente 'A' \"x\" </script>"]);
        $article = app(ArticleService::class)->createDraft($this->a);

        $html = $this->actingAs($this->admin())->get(route('articles.edit', [$this->a, $article]))->assertOk()->getContent();

        $this->assertStringNotContainsString('</script>"', $html);
        $this->assertStringNotContainsString('x" </script>', $html);
        $this->assertStringNotContainsString("Cliente 'A'", $html);
    }
}
