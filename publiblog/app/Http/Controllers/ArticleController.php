<?php

namespace App\Http\Controllers;

use App\Exceptions\PublishException;
use App\Exceptions\VersionConflictException;
use App\Http\Requests\ArticleRequest;
use App\Jobs\PublishArticle;
use App\Jobs\UnpublishArticle;
use App\Models\Article;
use App\Models\Blog;
use App\Models\Deployment;
use App\Models\Media;
use App\Services\ArticleService;
use App\Services\MediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function __construct(private ArticleService $articles)
    {
    }

    public function index(Request $request, Blog $blog): View
    {
        $status = $request->query('status');

        $articles = $blog->articles()
            ->with('lastDeployment')
            ->when(in_array($status, ['draft', 'published', 'error'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('public_id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.articles.index', ['blog' => $blog, 'articles' => $articles, 'status' => $status]);
    }

    /** "Nuevo artículo": abre un borrador en blanco con su ID público ya asignado. */
    public function store(Request $request, Blog $blog): RedirectResponse
    {
        try {
            $article = $this->articles->createDraft($blog, $request->user());
        } catch (PublishException $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('articles.edit', [$blog, $article]);
    }

    public function edit(Blog $blog, Article $article): View
    {
        $article->setRelation('blog', $blog);
        $json = $this->articles->load($article);

        $featured = null;
        if (! empty($json['featured_image']['file'])) {
            $featured = Media::where('blog_id', $blog->id)->where('remote_path', $json['featured_image']['file'])->first();
        }

        return view('admin.articles.edit', [
            'blog' => $blog,
            'article' => $article,
            'json' => $json,
            'featured' => $featured,
            'maxUploadBytes' => MediaService::maxUploadBytes(),
            'deployments' => $article->deployments()->latest('id')->limit(8)->get(),
            'publishedAtInput' => $article->published_at?->setTimezone($blog->timezone)->format('Y-m-d\TH:i')
                ?? now($blog->timezone)->format('Y-m-d\TH:i'),
        ]);
    }

    public function update(ArticleRequest $request, Blog $blog, Article $article): RedirectResponse
    {
        $article->setRelation('blog', $blog);

        try {
            $this->articles->save($article, $request->validated(), $request->user(), $request->boolean('force'));
        } catch (VersionConflictException $e) {
            return back()->withInput()->with('conflict', true)
                ->with('error', 'Otra persona guardó este artículo mientras lo editabas. Si continúas, sobrescribirás sus cambios.');
        } catch (PublishException $e) {
            // local_io: el estado del artículo no cambia y el detalle queda en el log.
            report($e);

            return back()->withInput()->with('error', $e->getMessage());
        }

        if (! $request->isPublish()) {
            return redirect()->route('articles.edit', [$blog, $article])->with('status', 'Borrador guardado.');
        }

        return $this->publish($blog, $article, $request);
    }

    /** Reintentar vuelve a ejecutar el trabajo completo: los pasos remotos son idempotentes. */
    public function retry(Request $request, Blog $blog, Article $article): RedirectResponse
    {
        $article->setRelation('blog', $blog);

        // Si lo que falló fue una eliminación, reintentar es eliminar, no volver a publicar.
        if ($article->lastDeployment?->action === 'unpublish') {
            return $this->destroy($request, $blog, $article);
        }

        return $this->publish($blog, $article, $request);
    }

    public function destroy(Request $request, Blog $blog, Article $article): RedirectResponse
    {
        UnpublishArticle::dispatchSync($article->id, $request->user()->id);

        $deployment = Deployment::where('article_id', $article->id)->latest('id')->first();

        if ($deployment?->status === Deployment::STATUS_ERROR) {
            return redirect()->route('articles.edit', [$blog, $article])->with('error', $deployment->error_message);
        }

        return redirect()->route('articles.index', $blog)->with('status', 'Artículo eliminado. El JSON se conserva en el CMS.');
    }

    private function publish(Blog $blog, Article $article, Request $request): RedirectResponse
    {
        PublishArticle::dispatchSync($article->id, $request->user()->id);

        $article->refresh();
        $deployment = $article->lastDeployment;

        if ($deployment?->status === Deployment::STATUS_OK) {
            return redirect()->route('articles.edit', [$blog, $article])
                ->with('status', 'Publicado: '.$article->url());
        }

        return redirect()->route('articles.edit', [$blog, $article])
            ->with('error', $deployment?->error_message ?? 'No se pudo publicar.')
            ->with('retry', $deployment?->error_code);
    }
}
