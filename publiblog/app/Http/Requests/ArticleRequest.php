<?php

namespace App\Http\Requests;

use App\Models\Article;
use App\Models\Blog;
use App\Models\Category;
use App\Services\SlugService;
use Illuminate\Foundation\Http\FormRequest;

class ArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('write', $this->route('blog'))
            && $user->can('update', $this->route('article'));
    }

    public function isPublish(): bool
    {
        return $this->input('action') === 'publish';
    }

    public function rules(): array
    {
        /** @var Blog $blog */
        $blog = $this->route('blog');
        /** @var Article $article */
        $article = $this->route('article');
        $slugs = app(SlugService::class);
        $publish = $this->isPublish();

        return [
            'action' => ['required', 'in:save,publish'],
            'title' => [$publish ? 'required' : 'nullable', 'string', 'max:200'],
            'slug' => [
                'nullable', 'string', 'max:'.config('publiblog.slug_max_length'),
                function ($attribute, $value, $fail) use ($article, $blog, $slugs) {
                    if ($article->slugIsLocked() || blank($value)) {
                        return;
                    }

                    $slug = $slugs->normalize($value);

                    if (! $slugs->isValid($slug)) {
                        $fail('El slug solo puede llevar letras, números y guiones.');
                    } elseif ($slugs->isReserved($slug)) {
                        $fail("«{$slug}» es una palabra reservada.");
                    } elseif ($slugs->isTaken($blog, $slug, $article)) {
                        $fail("Ya existe un artículo con el slug «{$slug}».");
                    }
                },
            ],
            'category_ids' => [
                'nullable', 'array', 'max:30',
                function ($attribute, $value, $fail) use ($blog, $publish) {
                    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $value))));

                    // La principal forma la URL y no se asigna por omisión: para publicar hay que elegir.
                    if ($publish && ! $ids) {
                        $fail('Elige al menos una categoría para publicar: la principal forma la dirección del artículo.');
                    }

                    if ($ids &&Category::where('blog_id', $blog->id)->whereIn('id', $ids)->count() !== count($ids)) {
                        $fail('Alguna de las categorías no pertenece a este blog.');
                    }
                },
            ],
            'category_ids.*' => ['nullable', 'integer'],
            'primary_category_id' => [
                'nullable', 'integer',
                function ($attribute, $value, $fail) use ($blog) {
                    if ($value && ! Category::where('blog_id', $blog->id)->whereKey($value)->exists()) {
                        $fail('La categoría principal no pertenece a este blog.');
                    }
                },
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:200'],
            'body_html' => [$publish ? 'required' : 'nullable', 'string', 'max:2000000'],
            'featured_media_id' => ['nullable', 'integer'],
            'featured_alt' => ['nullable', 'string', 'max:200'],
            'author_name' => ['nullable', 'string', 'max:120'],
            'published_at' => [$publish ? 'required' : 'nullable', 'date'],
            'version' => ['required', 'integer'],
            'force' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio para publicar.',
            'body_html.required' => 'El contenido es obligatorio para publicar.',
            'published_at.required' => 'La fecha de publicación es obligatoria.',
        ];
    }
}
