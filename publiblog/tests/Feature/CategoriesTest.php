<?php

namespace Tests\Feature;

use App\Jobs\PublishArticle;
use App\Jobs\UnpublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Category;
use App\Models\User;
use App\Services\ArticleIndexer;
use App\Services\ArticleLocator;
use App\Services\ArticleService;
use App\Services\ArticleStorage;
use App\Services\BlogRegenerator;
use App\Services\BlogVerifier;
use App\Services\CategoryService;
use App\Services\HtaccessManager;
use App\Services\Remote\RemoteWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** F3: categorías (varias por artículo, la principal en la URL), movimiento de archivos y redirecciones 301. */
class CategoriesTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private Blog $blog;
    private CategoryService $categories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
        $this->blog = $this->makeBlog(['verify_http' => true, 'posts_per_page' => 2]);
        $this->fakeVerification();
        $this->categories = app(CategoryService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function category(string $name, ?string $slug = null): Category
    {
        return $this->categories->create($this->blog, ['name' => $name, 'slug' => $slug]);
    }

    /** @param list<Category>|null $cats la primera es la principal; null = la categoría «General» (la elige quien edita) */
    private function article(string $title, int $day = 1, ?array $cats = null, bool $publish = true): Article
    {
        $service = app(ArticleService::class);
        $article = $service->createDraft($this->blog);
        $input = [
            'title' => $title, 'body_html' => "<p>Cuerpo de {$title}</p>", 'excerpt' => "Extracto de {$title}",
            'published_at' => sprintf('2026-11-%02dT09:00', $day), 'version' => $article->version,
        ];

        $input += $cats !== null
            ? ['category_ids' => array_map(fn ($c) => $c->id, $cats), 'primary_category_id' => $cats[0]->id]
            : $this->chooseCategory($this->blog);

        $service->save($article, $input);

        if ($publish) {
            $this->publish($article);
        }

        return $article->fresh();
    }

    private function publish(Article $article): void
    {
        PublishArticle::dispatchSync($article->id);
    }

    private function recategorize(Article $article, array $cats): void
    {
        $article = $article->fresh();
        app(ArticleService::class)->save($article, [
            'title' => $article->title, 'body_html' => '<p>Cuerpo</p>', 'published_at' => '2026-11-01T09:00',
            'category_ids' => array_map(fn ($c) => $c->id, $cats), 'primary_category_id' => $cats[0]->id,
        ]);
    }

    private function remote(string $path): string
    {
        return file_get_contents($this->remoteDir.'/'.$path);
    }

    private function htaccess(): string
    {
        return $this->remote('.htaccess');
    }

    // ───────── sin categoría por omisión ─────────

    public function test_a_new_blog_has_no_categories_and_a_new_draft_gets_none(): void
    {
        $article = app(ArticleService::class)->createDraft($this->blog);
        $json = app(ArticleStorage::class)->readJson($this->blog->id, $article->public_id);

        $this->assertSame([], $json['categories']);
        $this->assertSame(0, Category::where('blog_id', $this->blog->id)->count()); // «General» ya no se crea sola

        // Abrir el editor y el listado de categorías tampoco la crean.
        $this->actingAs($this->admin());
        $this->get(route('articles.edit', [$this->blog, $article]))->assertOk()->assertSee('aún no tiene categorías');
        $this->get(route('categories.index', $this->blog))->assertOk()->assertSee('aún no tiene categorías');
        $this->assertSame(0, Category::where('blog_id', $this->blog->id)->count());
    }

    public function test_publishing_without_choosing_a_category_is_blocked_and_nothing_is_uploaded(): void
    {
        $this->category('Cocina'); // existe, pero nadie la eligió
        $article = app(ArticleService::class)->createDraft($this->blog);
        $this->actingAs($this->admin());

        // Desde el formulario: error de validación junto al selector, sin guardar ni subir nada.
        $this->put(route('articles.update', [$this->blog, $article]), [
            'action' => 'publish', 'title' => 'Sin elegir', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00',
            'version' => $article->version, 'category_ids' => [''],
        ])->assertSessionHasErrors('category_ids');

        // Y aunque llegara al publicador (p. ej. un borrador guardado antes), no sube nada.
        app(ArticleService::class)->save($article->fresh(), ['title' => 'Sin elegir', 'body_html' => '<p>x</p>', 'published_at' => '2026-11-10T09:00']);
        $this->publish($article);

        $deployment = $article->fresh()->lastDeployment;
        $this->assertSame('error', $deployment->status);
        $this->assertStringContainsString('categoría', $deployment->error_message);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertFileDoesNotExist($this->remoteDir.'/.htaccess');
    }

    public function test_a_niche_category_chosen_as_the_only_one_forms_the_url_without_any_general_category(): void
    {
        $bano = $this->category('Reformas de baño');
        $article = $this->article('Alicatar un baño', 1, [$bano]);

        $this->assertSame('reformas-de-bano/alicatar-un-bano.html', $article->remote_path);
        $this->assertSame(1, Category::where('blog_id', $this->blog->id)->count());
        $this->assertDatabaseMissing('categories', ['blog_id' => $this->blog->id, 'slug' => 'general']);
    }

    public function test_saving_without_the_category_key_keeps_them_and_with_it_empty_leaves_the_article_without_category(): void
    {
        $cocina = $this->category('Cocina');
        $article = $this->article('Uno', 1, [$cocina], publish: false);
        $json = fn () => app(ArticleStorage::class)->readJson($this->blog->id, $article->public_id);

        app(ArticleService::class)->save($article->fresh(), ['title' => 'Uno bis', 'body_html' => '<p>x</p>']);
        $this->assertSame('cocina', $json()['categories'][0]['slug']);

        app(ArticleService::class)->save($article->fresh(), ['title' => 'Uno', 'body_html' => '<p>x</p>', 'category_ids' => ['']]);
        $this->assertSame([], $json()['categories']);
    }

    public function test_the_reserve_category_is_optional_unmarkable_and_deletable(): void
    {
        $service = app(CategoryService::class);
        $this->assertNull($service->defaultFor($this->blog));

        $reserva = $service->create($this->blog, ['name' => 'Reserva', 'is_default' => true]);
        $this->assertSame($reserva->id, $service->defaultFor($this->blog)->id);

        $service->update($reserva, ['name' => 'Reserva', 'is_default' => false]);
        $this->assertNull($service->defaultFor($this->blog));

        $service->makeDefault($reserva);
        $service->delete($reserva->fresh()); // ya no hay trabas por ser la de reserva
        $this->assertDatabaseMissing('categories', ['id' => $reserva->id]);
    }

    public function test_the_primary_category_is_first_and_forms_the_url_and_canonical(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Mi artículo', 1, [$reformas, $cocina]);

        $this->assertSame('reformas/mi-articulo.html', $article->remote_path);
        $this->assertSame('https://cliente-a.com/blog/reformas/mi-articulo', $article->url());
        $this->assertFileExists($this->remoteDir.'/reformas/mi-articulo.html');
        $this->assertFileDoesNotExist($this->remoteDir.'/cocina/mi-articulo.html'); // sin copias

        $html = $this->remote('reformas/mi-articulo.html');
        $this->assertStringContainsString('<link rel="canonical" href="https://cliente-a.com/blog/reformas/mi-articulo">', $html);
        $this->assertStringContainsString('href="https://cliente-a.com/blog/cocina/"', $html); // también enlaza a la secundaria
    }

    // ───────── listados por categoría ─────────

    public function test_category_listings_are_generated_for_every_category_and_link_to_the_canonical_url(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $this->article('Solo cocina', 1, [$cocina]);
        $this->article('Las dos', 2, [$reformas, $cocina]);

        $this->assertFileExists($this->remoteDir.'/cocina/index.html');
        $this->assertFileExists($this->remoteDir.'/reformas/index.html');

        $cocinaPage = $this->remote('cocina/index.html');
        $this->assertStringContainsString('Solo cocina', $cocinaPage);
        $this->assertStringContainsString('Las dos', $cocinaPage);
        $this->assertStringContainsString('href="https://cliente-a.com/blog/reformas/las-dos"', $cocinaPage); // su URL canónica, no una copia
        $this->assertStringContainsString('<link rel="canonical" href="https://cliente-a.com/blog/cocina/">', $cocinaPage);
        $this->assertStringContainsString('<h1 class="list-title">Cocina</h1>', $cocinaPage);

        $reformasPage = $this->remote('reformas/index.html');
        $this->assertStringContainsString('Las dos', $reformasPage);
        $this->assertStringNotContainsString('Solo cocina', $reformasPage);

        // El listado general las tiene todas y el menú enlaza a las categorías.
        $all = $this->remote('index.html');
        $this->assertStringContainsString('Solo cocina', $all);
        $this->assertStringContainsString('href="https://cliente-a.com/blog/cocina/"', $all);
        $this->assertStringContainsString('href="https://cliente-a.com/blog/reformas/"', $all);
    }

    public function test_category_listings_are_paginated_with_their_own_urls(): void
    {
        $cocina = $this->category('Cocina');

        foreach (range(1, 5) as $i) {
            $this->article("Receta {$i}", $i, [$cocina]);
        }

        $this->assertFileExists($this->remoteDir.'/cocina/index.html');
        $this->assertFileExists($this->remoteDir.'/cocina/pagina/2/index.html');
        $this->assertFileExists($this->remoteDir.'/cocina/pagina/3/index.html');
        $this->assertFileDoesNotExist($this->remoteDir.'/cocina/pagina/4/index.html');

        $p2 = $this->remote('cocina/pagina/2/index.html');
        $this->assertStringContainsString('<link rel="canonical" href="https://cliente-a.com/blog/cocina/pagina/2/">', $p2);
        $this->assertStringContainsString('<link rel="prev" href="https://cliente-a.com/blog/cocina/">', $p2);
        $this->assertStringContainsString('<link rel="next" href="https://cliente-a.com/blog/cocina/pagina/3/">', $p2);
    }

    public function test_an_unrelated_category_page_is_not_reuploaded_when_another_category_changes(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $this->article('Una', 1, [$cocina]);
        $this->article('Dos', 2, [$reformas]);

        $before = hash_file('sha256', $this->remoteDir.'/cocina/index.html');
        $mtime = filemtime($this->remoteDir.'/cocina/index.html');
        clearstatcache();

        $this->article('Tres', 3, [$reformas]);

        $this->assertSame($before, hash_file('sha256', $this->remoteDir.'/cocina/index.html'));
        $this->assertSame($mtime, filemtime($this->remoteDir.'/cocina/index.html'));
    }

    public function test_unpublished_category_edits_do_not_reach_the_listings(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Estable', 1, [$cocina]);
        $this->article('Otro', 2, [$cocina]);

        // Se cambia la categoría en el borrador, sin publicar. Luego se publica OTRO artículo.
        $this->recategorize($article, [$reformas]);
        $this->article('Tercero', 3, [$cocina]);

        $this->assertStringContainsString('Estable', $this->remote('cocina/index.html').$this->remote('cocina/pagina/2/index.html'));
        $this->assertFileDoesNotExist($this->remoteDir.'/reformas/index.html');
        $this->assertSame('cocina/estable.html', $article->fresh()->remote_path);
    }

    public function test_a_category_without_published_articles_has_no_listing_and_no_empty_folder(): void
    {
        $cocina = $this->category('Cocina');
        $article = $this->article('Solo uno', 1, [$cocina]);
        $this->assertFileExists($this->remoteDir.'/cocina/index.html');

        UnpublishArticle::dispatchSync($article->id);

        $this->assertDirectoryDoesNotExist($this->remoteDir.'/cocina');
        $this->assertStringNotContainsString('/blog/cocina/', $this->remote('sitemap.xml'));
    }

    public function test_the_sitemap_lists_articles_and_category_pages(): void
    {
        $cocina = $this->category('Cocina');
        $this->article('Receta', 1, [$cocina]);

        $sitemap = $this->remote('sitemap.xml');
        $this->assertStringContainsString('<loc>https://cliente-a.com/blog/cocina/receta</loc>', $sitemap);
        $this->assertStringContainsString('<loc>https://cliente-a.com/blog/cocina/</loc>', $sitemap);
    }

    // ───────── cambio de categoría principal: mover + 301 ─────────

    public function test_changing_the_primary_category_moves_the_file_and_redirects_the_old_url(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Mueve', 1, [$cocina]);
        $this->assertFileExists($this->remoteDir.'/cocina/mueve.html');

        $this->recategorize($article, [$reformas, $cocina]);
        $this->publish($article);
        $article->refresh();

        $this->assertSame('reformas/mueve.html', $article->remote_path);
        $this->assertFileExists($this->remoteDir.'/reformas/mueve.html');
        $this->assertFileDoesNotExist($this->remoteDir.'/cocina/mueve.html'); // sin copia vieja
        $this->assertSame(['cocina/mueve'], $article->redirects);

        $this->assertStringContainsString(
            'RewriteRule ^cocina/mueve/?$ https://cliente-a.com/blog/reformas/mueve [R=301,L]',
            $this->htaccess()
        );

        // Sigue en las dos categorías; la URL de la tarjeta es la nueva.
        $this->assertStringContainsString('href="https://cliente-a.com/blog/reformas/mueve"', $this->remote('cocina/index.html'));
        $this->assertStringNotContainsString('/blog/cocina/mueve"', $this->remote('index.html'));
        $this->assertStringContainsString('/blog/reformas/mueve', $this->remote('sitemap.xml'));
        $this->assertStringNotContainsString('/blog/cocina/mueve', $this->remote('sitemap.xml'));
    }

    public function test_moving_away_removes_the_old_category_listing_when_it_empties(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Mueve', 1, [$cocina]);

        $this->recategorize($article, [$reformas]);
        $this->publish($article);

        $this->assertDirectoryDoesNotExist($this->remoteDir.'/cocina'); // ni archivos ni carpeta vacía
        $this->assertFileExists($this->remoteDir.'/reformas/index.html');
    }

    public function test_going_back_to_the_first_category_drops_the_self_redirect(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Vuelve', 1, [$cocina]);

        $this->recategorize($article, [$reformas]);
        $this->publish($article);
        $this->recategorize($article, [$cocina]);
        $this->publish($article);
        $article->refresh();

        $this->assertSame('cocina/vuelve.html', $article->remote_path);
        $this->assertSame(['reformas/vuelve'], $article->redirects); // no se redirige a sí misma
        $this->assertStringNotContainsString('^cocina/vuelve/?$', $this->htaccess());
        $this->assertStringContainsString('RewriteRule ^reformas/vuelve/?$ https://cliente-a.com/blog/cocina/vuelve [R=301,L]', $this->htaccess());
    }

    public function test_a_failed_move_keeps_the_previous_location_and_retry_completes_it(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Fallo', 1, [$cocina]);

        $this->recategorize($article, [$reformas]);

        // Falla el listado a mitad de publicación (después de mover el archivo).
        $dir = $this->remoteDir;
        $this->app->bind(\App\Services\Remote\RemoteFilesystemFactory::class, fn () => new class($dir) extends \App\Services\Remote\RemoteFilesystemFactory {
            public function __construct(private string $dir)
            {
            }

            public function make(Blog $blog): \League\Flysystem\FilesystemOperator
            {
                return new Filesystem(new class($this->dir) extends LocalFilesystemAdapter {
                    public function write(string $path, string $contents, \League\Flysystem\Config $config): void
                    {
                        if (str_contains($path, 'index.html')) {
                            throw \League\Flysystem\UnableToWriteFile::atLocation($path, 'Permission denied');
                        }
                        parent::write($path, $contents, $config);
                    }
                });
            }
        });

        $this->publish($article);
        $this->assertSame('error', $article->fresh()->status);
        $this->assertFileExists($this->remoteDir.'/reformas/fallo.html');

        $this->app->bind(\App\Services\Remote\RemoteFilesystemFactory::class, fn () => new \App\Services\Remote\RemoteFilesystemFactory());
        $this->publish($article->fresh());
        $article->refresh();

        $this->assertSame('published', $article->status);
        $this->assertSame('reformas/fallo.html', $article->remote_path);
        $this->assertFileDoesNotExist($this->remoteDir.'/cocina/fallo.html');
        $this->assertContains('cocina/fallo', $article->redirects);
        $this->assertFileExists($this->remoteDir.'/reformas/index.html');
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/cocina');
    }

    public function test_unpublishing_removes_the_file_the_folders_and_its_redirects(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Adiós', 1, [$cocina]);
        $this->recategorize($article, [$reformas]);
        $this->publish($article);
        $this->assertStringContainsString('^cocina/adios/?$', $this->htaccess());

        UnpublishArticle::dispatchSync($article->id);

        $this->assertDirectoryDoesNotExist($this->remoteDir.'/reformas');
        $this->assertStringNotContainsString('adios', $this->htaccess());
        $this->assertSame(0, $article->fresh()->liveCategories()->count() ?? 0);
    }

    // ───────── artículos anteriores a las categorías ─────────

    /** Deja un artículo publicado como estaba antes de las categorías: archivo en la raíz, sin categorías. */
    private function makeLegacy(Article $article): Article
    {
        $article = $article->fresh();
        $legacy = $article->public_id.'-'.$article->slug.'.html';
        $storage = app(ArticleStorage::class);

        File::move($this->remoteDir.'/'.$article->remote_path, $this->remoteDir.'/'.$legacy);
        $article->forceFill(['remote_path' => $legacy, 'redirects' => null, 'live_summary' => null])->save();
        $article->liveCategories()->detach();

        foreach (['readJson' => 'writeJson', 'readLiveJson' => 'writePublishedJson'] as $read => $write) {
            $json = $storage->{$read}($this->blog->id, $article->public_id);
            unset($json['categories']);
            $storage->{$write}($this->blog->id, $article->public_id, $json);
        }

        // El bloque del servidor como lo dejaba la versión anterior.
        $block = app(HtaccessManager::class)->buildBlock($this->blog);
        RemoteWriter::put(new Filesystem(new LocalFilesystemAdapter($this->remoteDir)), '.htaccess', $block."\n");

        return $article->fresh();
    }

    public function test_legacy_articles_keep_working_and_are_listed(): void
    {
        $article = $this->makeLegacy($this->article('Antiguo', 1));

        $this->assertStringContainsString('RewriteRule ^antiguo/?$ '.$article->remote_path.' [END]', $this->htaccess());
        $this->assertSame('https://cliente-a.com/blog/antiguo', $article->url());

        // Publicar OTRO artículo reescribe el bloque: la regla del antiguo se conserva y su listado también.
        $new = $this->article('Nuevo', 2);
        $this->assertSame('ok', $new->lastDeployment->status, (string) $new->lastDeployment->error_message);
        $this->assertStringContainsString('^antiguo/?$', $this->htaccess());
        $this->assertStringContainsString('href="https://cliente-a.com/blog/antiguo"', $this->remote('index.html'));
        $this->assertStringContainsString('/blog/antiguo</loc>', $this->remote('sitemap.xml'));
    }

    public function test_republishing_a_legacy_article_moves_it_into_its_category_and_redirects_the_old_url(): void
    {
        $article = $this->makeLegacy($this->article('Antiguo', 1));
        $legacyFile = $article->remote_path;

        // Sin elegir categoría no se vuelve a publicar: el artículo antiguo sigue sirviéndose donde estaba.
        app(ArticleService::class)->save($article, ['title' => 'Antiguo', 'body_html' => '<p>Nuevo texto</p>', 'published_at' => '2026-11-01T09:00']);
        $this->publish($article);
        $this->assertSame('error', $article->fresh()->lastDeployment->status);
        $this->assertSame($legacyFile, $article->fresh()->remote_path);
        $this->assertFileExists($this->remoteDir.'/'.$legacyFile);

        // Con categoría elegida pasa a su carpeta.
        app(ArticleService::class)->save($article->fresh(), $this->chooseCategory($this->blog) + ['title' => 'Antiguo', 'body_html' => '<p>Nuevo texto</p>', 'published_at' => '2026-11-01T09:00']);
        $this->publish($article->fresh());
        $article->refresh();

        $this->assertSame('general/antiguo.html', $article->remote_path);
        $this->assertFileDoesNotExist($this->remoteDir.'/'.$legacyFile);
        $this->assertSame(['antiguo'], $article->redirects);
        $this->assertStringContainsString('RewriteRule ^antiguo/?$ https://cliente-a.com/blog/general/antiguo [R=301,L]', $this->htaccess());
        $this->assertStringNotContainsString(' '.$legacyFile.' [END]', $this->htaccess());
    }

    public function test_a_category_cannot_take_the_address_of_a_legacy_article(): void
    {
        $this->makeLegacy($this->article('Cocina', 1)); // su dirección es /blog/cocina

        $this->actingAs($this->admin())
            ->post(route('categories.store', $this->blog), ['name' => 'Cocina', 'slug' => 'cocina'])
            ->assertSessionHasErrors('slug');

        // Si el slug se propone solo a partir del nombre, se le busca otro.
        $other = $this->categories->create($this->blog, ['name' => 'Cocina']);
        $this->assertSame('cocina-2', $other->slug);
        $this->assertTrue($this->categories->collidesWithLegacyArticle($this->blog, 'cocina'));
    }

    public function test_regenerating_leaves_legacy_articles_where_they_are_until_they_get_a_category(): void
    {
        $article = $this->makeLegacy($this->article('Antiguo', 1));

        $d = app(BlogRegenerator::class)->runAll(app(BlogRegenerator::class)->start($this->blog, force: true));

        $this->assertSame('ok', $d->status, (string) $d->error_message);
        $this->assertStringEndsWith('-antiguo.html', $article->fresh()->remote_path);
        $this->assertFileExists($this->remoteDir.'/'.$article->fresh()->remote_path);
        $this->assertStringContainsString('Antiguo', $this->remote('index.html')); // el listado se completó desde lo publicado
    }

    public function test_regeneration_moves_an_article_whose_published_categories_changed_location(): void
    {
        $cocina = $this->category('Cocina');
        $article = $this->article('Cambia', 1, [$cocina]);

        // Su copia publicada ya apunta a otra categoría principal (p. ej. tras un cambio hecho a mano en el JSON).
        $reformas = $this->category('Reformas');
        $storage = app(ArticleStorage::class);
        $live = $storage->readLiveJson($this->blog->id, $article->public_id);
        $live['categories'] = [['slug' => 'reformas', 'name' => 'Reformas']];
        $storage->writePublishedJson($this->blog->id, $article->public_id, $live);

        $d = app(BlogRegenerator::class)->runAll(app(BlogRegenerator::class)->start($this->blog));

        $this->assertSame('ok', $d->status, (string) $d->error_message);
        $this->assertSame('reformas/cambia.html', $article->fresh()->remote_path);
        $this->assertFileDoesNotExist($this->remoteDir.'/cocina/cambia.html');
        $this->assertStringContainsString('RewriteRule ^cocina/cambia/?$ https://cliente-a.com/blog/reformas/cambia [R=301,L]', $this->htaccess());
    }

    // ───────── .htaccess ─────────

    public function test_htaccess_block_is_fixed_size_and_does_not_grow_with_articles(): void
    {
        foreach (range(1, 6) as $i) {
            $this->article("Artículo {$i}", $i);
        }

        $block = app(HtaccessManager::class)->buildBlock($this->blog);

        $this->assertSame(3, substr_count($block, 'RewriteRule '));
        $this->assertStringNotContainsString('articulo-1', $block);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} -f [OR]', $block);
        $this->assertStringContainsString('RewriteRule ^(.+)/$ https://cliente-a.com/blog/$1 [R=301,L]', $block);
    }

    public function test_publishing_a_second_article_does_not_rewrite_the_htaccess(): void
    {
        $this->article('Primero', 1);
        $mtime = filemtime($this->remoteDir.'/.htaccess');
        $content = $this->htaccess();
        clearstatcache();

        $second = $this->article('Segundo', 2);

        $this->assertSame($content, $this->htaccess());
        $this->assertSame(4, $second->lastDeployment->files_count); // html + index + general/index + sitemap
        $this->assertSame($mtime, filemtime($this->remoteDir.'/.htaccess'));
    }

    public function test_redirect_targets_use_a_safe_origin_or_fall_back_to_a_path(): void
    {
        $article = $this->article('Redirige', 1);
        $article->forceFill(['redirects' => ['viejo/redirige']])->save();

        $this->blog->update(['base_url' => 'https://cliente-a.com/inyecta"; evil']);
        $block = app(HtaccessManager::class)->buildBlock($this->blog->fresh());

        $this->assertStringContainsString('RewriteRule ^viejo/redirige/?$ /blog/general/redirige [R=301,L]', $block);
        $this->assertStringNotContainsString('evil', $block);
    }

    // ───────── verificación ─────────

    public function test_verify_reports_a_lost_redirect_of_a_moved_article(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Mueve', 1, [$cocina]);
        $this->recategorize($article, [$reformas]);
        $this->publish($article);

        $verifier = app(BlogVerifier::class);
        $verifier->runAll($verifier->start($this->blog));
        $this->assertSame('ok', $article->fresh()->remote_state);

        file_put_contents($this->remoteDir.'/.htaccess', preg_replace('/RewriteRule \^cocina\/mueve.*\n/', '', $this->htaccess()));

        $verifier->runAll($verifier->start($this->blog));
        $this->assertSame('no_rule', $article->fresh()->remote_state);
    }

    // ───────── reconstrucción del índice ─────────

    public function test_reindex_rebuilds_categories_live_state_and_redirects(): void
    {
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = $this->article('Reindexa', 1, [$cocina]);
        $this->recategorize($article, [$reformas, $cocina]);
        $this->publish($article);

        Article::withTrashed()->forceDelete();
        Category::query()->delete();
        $this->blog->update(['last_public_id' => 0]);

        app(ArticleIndexer::class)->reindex($this->blog->fresh());

        $rebuilt = Article::sole();
        $this->assertSame('reformas/reindexa.html', $rebuilt->remote_path);
        $this->assertSame(['cocina/reindexa'], $rebuilt->redirects);
        $this->assertEqualsCanonicalizing(['cocina', 'reformas'], $rebuilt->liveCategories()->pluck('slug')->all());
        $this->assertSame('reformas', $rebuilt->liveCategories()->wherePivot('is_primary', true)->value('slug'));
        $this->assertSame('Reformas', Category::where('slug', 'reformas')->value('name'));
        $this->assertNotNull($rebuilt->live_summary);

        // Y el listado se puede reconstruir igual que antes.
        $built = app(\App\Services\ListingBuilder::class)->build($this->blog->fresh());
        $this->assertContains('cocina/index.html', array_keys($built));
        $this->assertContains('reformas/index.html', array_keys($built));
    }

    // ───────── gestión de categorías (panel) ─────────

    public function test_superadmin_creates_edits_and_deletes_categories(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('categories.store', $this->blog), ['name' => 'Recetas fáciles'])
            ->assertRedirect(route('categories.index', $this->blog));

        $category = Category::where('slug', 'recetas-faciles')->sole();
        $this->get(route('categories.index', $this->blog))->assertOk()->assertSee('Recetas fáciles');

        // El slug no cambia aunque se intente.
        $this->put(route('categories.update', [$this->blog, $category]), ['name' => 'Recetas', 'slug' => 'otra-cosa', 'description' => 'Para cocinar'])
            ->assertRedirect(route('categories.index', $this->blog));
        $category->refresh();
        $this->assertSame('recetas-faciles', $category->slug);
        $this->assertSame('Recetas', $category->name);

        $this->delete(route('categories.destroy', [$this->blog, $category]))->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_renaming_a_category_updates_the_listings_at_once_and_flags_the_article_pages_until_regenerated(): void
    {
        $cocina = $this->category('Cocina');
        $article = $this->article('Receta uno', 1, [$cocina]);
        $this->actingAs($this->admin());

        $this->put(route('categories.update', [$this->blog, $cocina]), ['name' => 'Cocina casera'])
            ->assertRedirect(route('categories.index', $this->blog))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'listados del blog ya están actualizados') && str_contains($m, 'regenerar'));

        // Menú y listado de la categoría ya llevan el nombre nuevo; el artículo todavía el viejo.
        $this->assertStringContainsString('Cocina casera', $this->remote('index.html'));
        $this->assertStringContainsString('Cocina casera', $this->remote('cocina/index.html'));
        $this->assertStringNotContainsString('Cocina casera', $this->remote('cocina/receta-uno.html'));

        // El panel lo recuerda hasta que se regenere.
        $this->assertNotNull($this->blog->fresh()->pages_stale_at);
        $this->get(route('blogs.show', $this->blog))->assertSee('Hay cambios en las categorías');

        $d = app(BlogRegenerator::class)->runAll(app(BlogRegenerator::class)->start($this->blog));

        $this->assertSame('ok', $d->status, (string) $d->error_message);
        $this->assertStringContainsString('Cocina casera', $this->remote('cocina/receta-uno.html'));
        $this->assertNull($this->blog->fresh()->pages_stale_at);
        $this->get(route('blogs.show', $this->blog))->assertDontSee('Hay cambios en las categorías');
        $this->assertSame('cocina/receta-uno.html', $article->fresh()->remote_path); // el slug no cambia: ninguna URL se mueve
    }

    public function test_changing_only_the_description_updates_the_category_listing_without_flagging_the_articles(): void
    {
        $cocina = $this->category('Cocina');
        $this->article('Receta uno', 1, [$cocina]);
        $this->actingAs($this->admin());

        $this->put(route('categories.update', [$this->blog, $cocina]), ['name' => 'Cocina', 'description' => 'Platos del día'])
            ->assertSessionHas('status', fn ($m) => ! str_contains($m, 'Falta regenerar'));

        $this->assertStringContainsString('Platos del día', $this->remote('cocina/index.html'));
        $this->assertNull($this->blog->fresh()->pages_stale_at);
    }

    public function test_renaming_a_category_without_published_articles_touches_nothing_remote(): void
    {
        $cocina = $this->category('Cocina');
        $this->article('Receta uno', 1, [$cocina]);
        $vacia = $this->category('Vacía');
        $before = $this->remote('index.html');
        $this->actingAs($this->admin());

        $this->put(route('categories.update', [$this->blog, $vacia]), ['name' => 'Vacía renombrada'])->assertSessionHas('status');

        $this->assertSame('Vacía renombrada', $vacia->fresh()->name);
        $this->assertSame($before, $this->remote('index.html'));
        $this->assertNull($this->blog->fresh()->pages_stale_at);
    }

    public function test_a_rename_is_saved_and_flagged_even_if_the_listings_cannot_be_updated_now(): void
    {
        $cocina = $this->category('Cocina');
        $this->article('Receta uno', 1, [$cocina]);
        $this->actingAs($this->admin());

        // Hay una regeneración en cola: los listados no se tocan a la vez.
        $regenerator = app(BlogRegenerator::class);
        $regenerator->start($this->blog);

        $this->put(route('categories.update', [$this->blog, $cocina]), ['name' => 'Cocina casera'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'No se pudieron actualizar los listados ahora'));

        $this->assertSame('Cocina casera', $cocina->fresh()->name);
        $this->assertNotNull($this->blog->fresh()->pages_stale_at);
        $this->assertStringNotContainsString('Cocina casera', $this->remote('cocina/index.html'));
    }

    public function test_a_regeneration_does_not_clear_a_change_made_after_it_started_or_one_limited_to_problems(): void
    {
        $cocina = $this->category('Cocina');
        $this->article('Receta uno', 1, [$cocina]);
        $regenerator = app(BlogRegenerator::class);

        // Cambio posterior al inicio de la regeneración: sigue pendiente.
        $this->blog->forceFill(['pages_stale_at' => now()->addMinutes(5)])->save();
        $regenerator->runAll($regenerator->start($this->blog));
        $this->assertNotNull($this->blog->fresh()->pages_stale_at);

        // Una regeneración parcial (solo artículos con problemas) no pone todo al día.
        $this->blog->forceFill(['pages_stale_at' => now()->subMinute()])->save();
        $regenerator->runAll($regenerator->start($this->blog, onlyProblems: true));
        $this->assertNotNull($this->blog->fresh()->pages_stale_at);

        // La completa sí.
        $regenerator->runAll($regenerator->start($this->blog));
        $this->assertNull($this->blog->fresh()->pages_stale_at);
    }

    public function test_category_slugs_must_be_valid_free_and_not_reserved(): void
    {
        $this->actingAs($this->admin());
        $this->category('Cocina');

        $this->post(route('categories.store', $this->blog), ['name' => 'Otra', 'slug' => 'cocina'])->assertSessionHasErrors('slug');
        $this->post(route('categories.store', $this->blog), ['name' => 'Otra', 'slug' => 'pagina'])->assertSessionHasErrors('slug');
        $this->post(route('categories.store', $this->blog), ['name' => 'Otra', 'slug' => 'img'])->assertSessionHasErrors('slug');
        $this->post(route('categories.store', $this->blog), ['name' => 'Otra', 'slug' => '../x'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('categories', ['slug' => '../x']);
        $this->assertTrue(Category::where('slug', 'x')->exists()); // normalizado, nunca con puntos ni barras
    }

    public function test_categories_with_published_articles_cannot_be_deleted_even_the_reserve_one(): void
    {
        $this->actingAs($this->admin());
        $cocina = $this->category('Cocina');
        $reserva = $this->categories->create($this->blog, ['name' => 'Reserva', 'is_default' => true]);
        $this->article('Publicado', 1, [$cocina]);
        $this->article('Publicado en reserva', 2, [$reserva]);

        $this->delete(route('categories.destroy', [$this->blog, $cocina]))->assertSessionHas('error');
        $this->delete(route('categories.destroy', [$this->blog, $reserva]))->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $cocina->id]);
        $this->assertDatabaseHas('categories', ['id' => $reserva->id]);
    }

    public function test_only_one_reserve_category_per_blog_and_new_drafts_do_not_receive_it(): void
    {
        $reserva = $this->category('Reserva');
        $cocina = $this->category('Cocina');
        $this->categories->update($reserva, ['name' => 'Reserva', 'is_default' => true]);

        $this->categories->update($cocina, ['name' => 'Cocina', 'is_default' => true]);

        $this->assertSame(1, Category::where('blog_id', $this->blog->id)->where('is_default', true)->count());
        $this->assertTrue($cocina->fresh()->is_default);
        $this->assertSame([], app(ArticleStorage::class)->readJson($this->blog->id, app(ArticleService::class)->createDraft($this->blog)->public_id)['categories']);
    }

    public function test_deleting_a_category_after_saving_a_draft_with_it_blocks_publishing_with_a_clear_message(): void
    {
        $cocina = $this->category('Cocina');
        $article = $this->article('Borrador', 1, [$cocina], publish: false);
        $cocina->delete();

        $this->publish($article);

        $deployment = $article->fresh()->lastDeployment;
        $this->assertSame('error', $deployment->status);
        $this->assertSame('validation', $deployment->error_code);
        $this->assertStringContainsString('«Cocina» ya no existe', $deployment->error_message);
        $this->assertNull($article->fresh()->remote_path); // nada llegó al servidor
    }

    public function test_the_article_form_saves_categories_and_primary(): void
    {
        $admin = $this->admin();
        $cocina = $this->category('Cocina');
        $reformas = $this->category('Reformas');
        $article = app(ArticleService::class)->createDraft($this->blog);

        $this->actingAs($admin)->get(route('articles.edit', [$this->blog, $article]))
            ->assertOk()->assertSee('Categorías')->assertSee('Cocina')->assertSee('Reformas');

        $this->actingAs($admin)->put(route('articles.update', [$this->blog, $article]), [
            'action' => 'save', 'title' => 'Con categorías', 'version' => $article->version,
            'category_ids' => ['', $cocina->id, $reformas->id], 'primary_category_id' => $reformas->id,
        ])->assertSessionHasNoErrors();

        $json = app(ArticleStorage::class)->readJson($this->blog->id, $article->public_id);
        $this->assertSame(['reformas', 'cocina'], array_column($json['categories'], 'slug'));
        $this->assertSame('https://cliente-a.com/blog/reformas/con-categorias', $json['seo']['canonical']);
    }

    public function test_categories_of_another_blog_are_rejected(): void
    {
        $other = $this->makeBlog(['name' => 'Otro', 'domain' => 'otro.com', 'base_url' => 'https://otro.com', 'remote_dir' => $this->remoteDir.'-otro']);
        $foreign = $this->categories->create($other, ['name' => 'Ajena']);
        $article = app(ArticleService::class)->createDraft($this->blog);

        $this->actingAs($this->admin())->put(route('articles.update', [$this->blog, $article]), [
            'action' => 'save', 'title' => 'X', 'version' => $article->version, 'category_ids' => [$foreign->id],
        ])->assertSessionHasErrors('category_ids');
    }

    public function test_blog_users_only_manage_the_categories_of_their_blogs(): void
    {
        $other = $this->makeBlog(['name' => 'Otro', 'domain' => 'otro.com', 'base_url' => 'https://otro.com', 'remote_dir' => $this->remoteDir.'-otro']);
        $theirs = $this->category('Mía');
        $foreign = $this->categories->create($other, ['name' => 'Ajena']);

        $user = User::factory()->create(['role' => User::ROLE_BLOG_USER]);
        $user->blogs()->attach($this->blog->id, ['role' => 'editor']);
        $this->actingAs($user);

        $this->get(route('categories.index', $this->blog))->assertOk()->assertSee('Mía');
        $this->post(route('categories.store', $this->blog), ['name' => 'Nueva'])->assertRedirect();
        $this->get(route('categories.index', $other))->assertNotFound();
        $this->get(route('categories.edit', [$other, $foreign]))->assertNotFound();
        // Con el bloque de otro cliente en la URL de su propio blog tampoco se llega a la categoría ajena.
        $this->get(route('categories.edit', [$this->blog, $foreign]))->assertNotFound();
        $this->put(route('categories.update', [$this->blog, $foreign]), ['name' => 'Hackeada'])->assertNotFound();
        $this->assertSame('Ajena', $foreign->fresh()->name);
        $this->assertSame('Mía', $theirs->fresh()->name);
    }

    // ───────── utilidades ─────────

    public function test_locator_builds_files_urls_and_validates_paths(): void
    {
        $this->assertSame('cocina/mi-post.html', ArticleLocator::file('cocina', 'mi-post'));
        $this->assertSame('cocina/mi-post', ArticleLocator::publicPath('cocina/mi-post.html'));
        $this->assertSame('mi-post', ArticleLocator::publicPath('000012-mi-post.html')); // formato anterior
        $this->assertTrue(ArticleLocator::isLegacy('000012-mi-post.html'));
        $this->assertFalse(ArticleLocator::isLegacy('cocina/mi-post.html'));

        foreach (['a', 'a/b', 'a-b/c-d', '2021/05/post'] as $ok) {
            $this->assertTrue(ArticleLocator::isValidPath($ok), $ok);
        }

        foreach (['', '/a', 'a/', 'A/b', 'a b', '../a', 'a//b', "a\nb", 'a.b', 'a/../b'] as $bad) {
            $this->assertFalse(ArticleLocator::isValidPath($bad), json_encode($bad));
        }

        $this->assertNull(ArticleLocator::fileFromJson(['slug' => 'x']));
        $this->assertSame('cat/x.html', ArticleLocator::fileFromJson(['slug' => 'x', 'categories' => [['slug' => 'cat', 'name' => 'Cat']]]));
    }

    public function test_redirect_source_only_for_same_domain_inside_the_blog_folder(): void
    {
        $blog = new Blog(['base_url' => 'https://cliente-a.com']);

        $this->assertSame('2021/05/post', ArticleLocator::redirectSource($blog, 'https://www.cliente-a.com/blog/2021/05/post/'));
        $this->assertSame('post', ArticleLocator::redirectSource($blog, 'https://cliente-a.com/blog/post'));
        $this->assertNull(ArticleLocator::redirectSource($blog, 'https://cliente-a.com/otra-seccion/post/')); // fuera de /blog/
        $this->assertNull(ArticleLocator::redirectSource($blog, 'https://antigua.com/blog/post/'));         // otro dominio
        $this->assertNull(ArticleLocator::redirectSource($blog, 'https://cliente-a.com/blog/'));
        $this->assertNull(ArticleLocator::redirectSource($blog, 'https://cliente-a.com/blog/Post con espacios/'));
        $this->assertNull(ArticleLocator::redirectSource($blog, 'no es una url'));
    }

    public function test_remote_remove_prunes_only_empty_folders_and_never_the_root(): void
    {
        $fs = new Filesystem(new LocalFilesystemAdapter($this->remoteDir));
        $fs->write('a/b/uno.html', 'x');
        $fs->write('a/otro.html', 'y');

        $this->assertTrue(RemoteWriter::remove($fs, 'a/b/uno.html'));
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/a/b'); // quedó vacía
        $this->assertDirectoryExists($this->remoteDir.'/a');         // aún tiene otro.html

        $this->assertTrue(RemoteWriter::remove($fs, 'a/otro.html'));
        $this->assertDirectoryDoesNotExist($this->remoteDir.'/a');
        $this->assertDirectoryExists($this->remoteDir); // la raíz nunca se borra

        $this->assertFalse(RemoteWriter::remove($fs, 'no-existe.html'));
    }
}
