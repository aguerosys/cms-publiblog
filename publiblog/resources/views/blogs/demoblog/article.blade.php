<!doctype html>
<html lang="{{ $blog->locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo->meta_title }}</title>
    <meta name="description" content="{{ $seo->meta_description }}">
    <link rel="canonical" href="{{ $seo->canonical }}">
    <meta name="blog-article-id" content="{{ $article->public_id }}">
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $seo->meta_title }}">
    <meta property="og:description" content="{{ $seo->meta_description }}">
    <meta property="og:url" content="{{ $seo->canonical }}">
    @if($article->featured_image)
    <meta property="og:image" content="{{ $blog->base_url }}{{ $article->featured_image->url }}">
    @endif
    <link rel="stylesheet" href="/css/blog.css">
</head>
<body>
@siteHeader

<main class="container">
    <article class="post">
        <header class="post-header">
            @if($article->category)
            <nav class="breadcrumb" aria-label="Migas de pan"><a href="/blog/">Blog</a> › <a href="{{ $article->category->url }}">{{ $article->category->name }}</a></nav>
            @endif
            <h1>{{ $article->title }}</h1>
            <p class="post-meta">
                <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->translatedFormat('j \d\e F \d\e Y') }}</time>
                @foreach($article->categories as $cat)
                    · <a href="{{ $cat->url }}">{{ $cat->name }}</a>
                @endforeach
                @if($article->author)
                    · {{ $article->author->name }}
                @endif
            </p>
        </header>

        @if($article->featured_image)
            <img class="post-cover"
                 src="{{ $article->featured_image->url }}"
                 alt="{{ $article->featured_image->alt }}"
                 @if($article->featured_image->width) width="{{ $article->featured_image->width }}" @endif
                 @if($article->featured_image->height) height="{{ $article->featured_image->height }}" @endif>
        @endif

        <div class="post-body">
            {!! $article->body_html !!}
        </div>
    </article>
</main>

@siteFooter
</body>
</html>
