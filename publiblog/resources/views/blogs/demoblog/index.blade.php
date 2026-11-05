<!doctype html>
<html lang="{{ $blog->locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo->meta_title }}</title>
    <meta name="description" content="{{ $seo->meta_description }}">
    <link rel="canonical" href="{{ $seo->canonical }}">
    @if($pagination->prev_url)
    <link rel="prev" href="{{ $pagination->prev_url }}">
    @endif
    @if($pagination->next_url)
    <link rel="next" href="{{ $pagination->next_url }}">
    @endif
    <link rel="stylesheet" href="/css/blog.css">
</head>
<body>
@include('blogs.demoblog.partials.header')

<main class="container">
    <h1 class="list-title">Blog</h1>

    @forelse($articles as $item)
        <article class="list-item">
            @if($item->featured_image)
                <a href="{{ $item->url }}"><img src="{{ $item->featured_image->url }}" alt="{{ $item->featured_image->alt }}"
                    @if($item->featured_image->width) width="{{ $item->featured_image->width }}" @endif
                    @if($item->featured_image->height) height="{{ $item->featured_image->height }}" @endif loading="lazy"></a>
            @endif
            <h2><a href="{{ $item->url }}">{{ $item->title }}</a></h2>
            <p class="post-meta"><time datetime="{{ $item->published_at->toIso8601String() }}">{{ $item->published_at->translatedFormat('j \d\e F \d\e Y') }}</time></p>
            <p>{{ $item->excerpt }}</p>
        </article>
    @empty
        <p>Todavía no hay artículos.</p>
    @endforelse

    @if($pagination->total > 1)
        <nav class="pager" aria-label="Paginación">
            @if($pagination->prev_url)<a href="{{ $pagination->prev_url }}" rel="prev">← Anteriores</a>@endif
            <span>Página {{ $pagination->current }} de {{ $pagination->total }}</span>
            @if($pagination->next_url)<a href="{{ $pagination->next_url }}" rel="next">Siguientes →</a>@endif
        </nav>
    @endif
</main>

@include('blogs.demoblog.partials.footer')
</body>
</html>
