<!DOCTYPE html>
<html lang="{{ $blog->locale }}">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{{ $seo->meta_title }}</title>
<meta name="description" content="{{ $seo->meta_description }}" />
<link rel="canonical" href="{{ $seo->canonical }}" />
<meta name="blog-article-id" content="{{ $article->public_id }}">
<meta name="robots" content="index, follow, max-image-preview:large" />
<meta property="og:type" content="article" />
<meta property="og:locale" content="es_ES" />
<meta property="og:site_name" content="Dialogfy" />
<meta property="og:title" content="{{ $seo->meta_title }}" />
<meta property="og:description" content="{{ $seo->meta_description }}" />
<meta property="og:url" content="{{ $seo->canonical }}" />
<meta property="article:published_time" content="{{ $article->published_at->toIso8601String() }}" />
<meta property="article:modified_time" content="{{ $article->updated_at->toIso8601String() }}" />
@if($article->category)
<meta property="article:section" content="{{ $article->category->name }}" />
@endif
@if($article->featured_image)
<meta property="og:image" content="{{ $blog->base_url }}{{ $article->featured_image->url }}" />
@endif
<meta name="twitter:card" content="summary_large_image" />
<link rel="icon" href="https://dialogfy.com/assets/dialogfy-icono.png" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://dialogfy.com/styles.css" />
<link rel="stylesheet" href="@templateAsset('blog.css')" />
<script>document.documentElement.classList.add('js');</script>
@structuredData
</head>
<body>

@siteHeader

<main id="contenido">
  <article class="entrada">
    <header class="entrada-cabecera">
      <div class="contenedor">
        <div class="entrada__columna">
          <nav class="migas" aria-label="Migas de pan">
            <a href="https://dialogfy.com/">Dialogfy</a>
            <svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>
            <a href="/blog/">Blog</a>
            @if($article->category)
            <svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>
            <a href="{{ $article->category->url }}">{{ $article->category->name }}</a>
            @endif
          </nav>
          <h1 class="entrada-titulo">{{ $article->title }}</h1>
          <p class="entrada-meta">
            <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->translatedFormat('j \d\e F \d\e Y') }}</time>
            @if($article->author) · {{ $article->author->name }} @endif
            · {{ $article->reading_minutes }} min de lectura
          </p>
        </div>
      </div>
    </header>

    @if($article->featured_image)
    <figure class="entrada-portada">
      <img src="{{ $article->featured_image->url }}" alt="{{ $article->featured_image->alt }}"
           @if($article->featured_image->width) width="{{ $article->featured_image->width }}" @endif
           @if($article->featured_image->height) height="{{ $article->featured_image->height }}" @endif
           fetchpriority="high" decoding="async" />
    </figure>
    @endif

    <div class="contenedor entrada-contenido">
      <div class="entrada__columna">
        <div class="post-body">
          {!! $article->body_html !!}
        </div>
      </div>
    </div>

    <footer class="contenedor entrada-pie">
      <div class="entrada__columna">
        @if(count($article->categories) > 0)
        <p class="entrada-pie__titulo">Categorías</p>
        <nav class="blog-categorias" aria-label="Categorías del artículo">
          @foreach($article->categories as $cat)
          <a href="{{ $cat->url }}">{{ $cat->name }}</a>
          @endforeach
        </nav>
        @endif
        <a class="entrada-pie__volver" href="/blog/"><svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>Volver al blog</a>
      </div>
    </footer>
  </article>
</main>

@siteFooter

</body>
</html>
