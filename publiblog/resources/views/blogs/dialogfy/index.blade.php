<!DOCTYPE html>
<html lang="{{ $blog->locale }}">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{{ $seo->meta_title }}</title>
<meta name="description" content="{{ $seo->meta_description }}" />
<link rel="canonical" href="{{ $seo->canonical }}" />
@if($pagination->prev_url)
<link rel="prev" href="{{ $pagination->prev_url }}" />
@endif
@if($pagination->next_url)
<link rel="next" href="{{ $pagination->next_url }}" />
@endif
<meta name="robots" content="index, follow, max-image-preview:large" />
<meta property="og:type" content="website" />
<meta property="og:locale" content="es_ES" />
<meta property="og:site_name" content="Dialogfy" />
<meta property="og:title" content="{{ $seo->meta_title }}" />
<meta property="og:description" content="{{ $seo->meta_description }}" />
<meta property="og:url" content="{{ $seo->canonical }}" />
@foreach($articles as $item)
@if($loop->first && $item->featured_image)
<meta property="og:image" content="{{ $blog->base_url }}{{ $item->featured_image->url }}" />
@endif
@endforeach
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
  <section class="blog-cabecera">
    <div class="contenedor">
      <nav class="migas" aria-label="Migas de pan">
        <a href="https://dialogfy.com/">Dialogfy</a>
        <svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>
        @if($category)
        <a href="/blog/">Blog</a>
        <svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>
        <span aria-current="page">{{ $category->name }}</span>
        @else
        <span aria-current="page">Blog</span>
        @endif
      </nav>
      <h1 class="blog-cabecera__titulo">{{ $category ? $category->name : 'Blog' }}</h1>
      @if($category && $category->description)
      <p class="blog-cabecera__intro">{{ $category->description }}</p>
      @elseif($category)
      <p class="blog-cabecera__intro">Artículos de {{ $category->name }} en el blog de Dialogfy.</p>
      @else
      <p class="blog-cabecera__intro">Artículos sobre atención al cliente, WhatsApp para empresas, agentes de inteligencia artificial y automatización de conversaciones.</p>
      @endif

      @if(count($categories) > 0)
      <nav class="blog-categorias" aria-label="Categorías">
        <a href="/blog/" @if(! $category) aria-current="page" @endif>Todas</a>
        @foreach($categories as $cat)
        <a href="{{ $cat->url }}" @if($category && $category->slug === $cat->slug) aria-current="page" @endif>{{ $cat->name }}</a>
        @endforeach
      </nav>
      @endif
    </div>
  </section>

  <section class="seccion blog-listado" aria-label="Artículos">
    <div class="contenedor">
      @if(count($articles) > 0)
      <div class="blog-rejilla">
        @foreach($articles as $item)
        <article class="blog-tarjeta">
          @if($item->featured_image)
          <a class="blog-tarjeta__imagen" href="{{ $item->url }}" tabindex="-1" aria-hidden="true">
            <img src="{{ $item->featured_image->url }}" alt=""
                 @if($item->featured_image->width) width="{{ $item->featured_image->width }}" @endif
                 @if($item->featured_image->height) height="{{ $item->featured_image->height }}" @endif
                 loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" decoding="async" />
          </a>
          @endif
          <div class="blog-tarjeta__cuerpo">
            <p class="blog-tarjeta__meta">@if($item->category)<a href="{{ $item->category->url }}">{{ $item->category->name }}</a> · @endif<time datetime="{{ $item->published_at->toIso8601String() }}">{{ $item->published_at->translatedFormat('j M Y') }}</time>@if($item->reading_minutes) · {{ $item->reading_minutes }} min @endif</p>
            <h2 class="blog-tarjeta__titulo"><a href="{{ $item->url }}">{{ $item->title }}</a></h2>
            <p class="blog-tarjeta__extracto">{{ $item->excerpt }}</p>
            <a class="blog-tarjeta__enlace" href="{{ $item->url }}" aria-label="Leer el artículo: {{ $item->title }}">Leer artículo<svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg></a>
          </div>
        </article>
        @endforeach
      </div>
      @else
      <p class="blog-vacio">Todavía no hay artículos.</p>
      @endif

      @if($pagination->total > 1)
      <nav class="blog-paginacion" aria-label="Paginación">
        @if($pagination->prev_url)
        <a class="blog-paginacion__anterior" href="{{ $pagination->prev_url }}" rel="prev"><svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>Anterior</a>
        @endif
        @foreach($pagination->pages as $p)
        @if($p->gap)
        <span class="blog-paginacion__hueco" aria-hidden="true">…</span>
        @elseif($p->current)
        <span aria-current="page">{{ $p->number }}</span>
        @else
        <a href="{{ $p->url }}">{{ $p->number }}</a>
        @endif
        @endforeach
        @if($pagination->next_url)
        <a class="blog-paginacion__siguiente" href="{{ $pagination->next_url }}" rel="next">Siguiente<svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg></a>
        @endif
      </nav>
      @endif
    </div>
  </section>
</main>

@siteFooter

</body>
</html>
