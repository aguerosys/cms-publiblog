<!DOCTYPE html>
<html lang="{{ $blog->locale }}">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{{ $seo->meta_title }} | Blog Dialogfy</title>
<meta name="description" content="{{ $seo->meta_description }}" />
<link rel="canonical" href="{{ $seo->canonical }}" />
<meta name="blog-article-id" content="{{ $article->public_id }}">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />
@if($article->author)
<meta name="author" content="{{ $article->author->name }}" />
@endif

<meta property="og:type" content="article" />
<meta property="og:locale" content="es_ES" />
<meta property="og:site_name" content="Dialogfy" />
<meta property="og:title" content="{{ $seo->meta_title }}" />
<meta property="og:description" content="{{ $seo->meta_description }}" />
<meta property="og:url" content="{{ $seo->canonical }}" />
@if($article->featured_image)
<meta property="og:image" content="{{ $blog->base_url }}{{ $article->featured_image->url }}" />
@if($article->featured_image->width)
<meta property="og:image:width" content="{{ $article->featured_image->width }}" />
@endif
@if($article->featured_image->height)
<meta property="og:image:height" content="{{ $article->featured_image->height }}" />
@endif
@endif
<meta property="article:published_time" content="{{ $article->published_at->toIso8601String() }}" />
<meta property="article:modified_time" content="{{ $article->updated_at->toIso8601String() }}" />
@if($article->author)
<meta property="article:author" content="{{ $article->author->name }}" />
@endif
@if($article->category)
<meta property="article:section" content="{{ $article->category->name }}" />
@endif
<meta name="twitter:card" content="summary_large_image" />
@if($article->author)
<meta name="twitter:label1" content="Escrito por" />
<meta name="twitter:data1" content="{{ $article->author->name }}" />
@endif
<meta name="twitter:label2" content="Tiempo de lectura" />
<meta name="twitter:data2" content="{{ $article->reading_minutes }} minutos" />

<link rel="icon" href="https://dialogfy.com/assets/dialogfy-icono.png" />
<link rel="apple-touch-icon" href="https://dialogfy.com/assets/dialogfy-icono.png" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet" />
@if($article->featured_image)
<link rel="preload" as="image" href="{{ $article->featured_image->url }}" />
@endif
<link rel="stylesheet" href="https://dialogfy.com/styles.css" />
<link rel="stylesheet" href="@templateAsset('post.css')" />
<script>document.documentElement.classList.add('js');</script>

@structuredData
</head>
<body>

@siteHeader

<main id="contenido">
  <article class="post">

    <header class="post__cabecera">
      <div class="contenedor post__estrecho">
        <nav class="migas" aria-label="Migas de pan">
          <a href="https://dialogfy.com/">Dialogfy</a>
          <svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>
          <a href="/blog/">Blog</a>
          @if($article->category)
          <svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>
          <a href="{{ $article->category->url }}">{{ $article->category->name }}</a>
          @endif
          <svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg>
          <span aria-current="page">{{ $article->title }}</span>
        </nav>
        @if($article->category)
        <p class="antetitulo"><a href="{{ $article->category->url }}">{{ $article->category->name }}</a></p>
        @endif
        <h1 class="post__titulo">{{ $article->title }}</h1>
        <p class="post__meta">
          @if($article->author)
          <span>Por <strong>{{ $article->author->name }}</strong></span>
          <span aria-hidden="true">·</span>
          @endif
          <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->translatedFormat('j \d\e F \d\e Y') }}</time>
          <span aria-hidden="true">·</span>
          <span>{{ $article->reading_minutes }} min de lectura</span>
        </p>
      </div>
      @if($article->featured_image)
      <figure class="contenedor post__portada">
        <img src="{{ $article->featured_image->url }}" alt="{{ $article->featured_image->alt }}"
             @if($article->featured_image->width) width="{{ $article->featured_image->width }}" @endif
             @if($article->featured_image->height) height="{{ $article->featured_image->height }}" @endif
             fetchpriority="high" decoding="async" />
      </figure>
      @endif
    </header>

    <div class="contenedor post__rejilla @if(count($article->toc) < 2) post__rejilla--sin-indice @endif">
      @if(count($article->toc) > 1)
      <aside class="post__indice" aria-labelledby="t-indice">
        <p class="post__indice-titulo" id="t-indice">Tabla de contenidos</p>
        <ol>
          @foreach($article->toc as $heading)
          <li><a href="#{{ $heading->id }}">{{ $heading->text }}</a></li>
          @endforeach
        </ol>
      </aside>
      @endif

      <div class="post__cuerpo">
        {!! $article->body_html !!}

        <footer class="post__pie">
          <p><time datetime="{{ $article->published_at->format('Y-m-d') }}">{{ $article->published_at->translatedFormat('j \d\e F \d\e Y') }}</time>@if($article->category) · <a href="{{ $article->category->url }}">{{ $article->category->name }}</a>@endif</p>
        </footer>
      </div>
    </div>
  </article>

  <aside class="seccion seccion--gris" aria-labelledby="t-relacionado">
    <div class="contenedor">
      <p class="antetitulo">Servicios relacionados</p>
      <h2 class="h2" id="t-relacionado" style="margin-top:14px;">Agentes de IA de Dialogfy</h2>
      <div class="rejilla rejilla--dos" style="margin-top:32px;">
        <a class="ficha" href="https://dialogfy.com/agente-ia-whatsapp.html"><svg aria-hidden="true" focusable="false"><use href="#i-bot"/></svg><h3>Agente de IA para WhatsApp</h3></a>
        <a class="ficha" href="https://dialogfy.com/agente-ia-llamadas.html"><svg aria-hidden="true" focusable="false"><use href="#i-llamada-entrante"/></svg><h3>Agente de IA para llamadas</h3></a>
      </div>
    </div>
  </aside>

  <section class="cierre" aria-labelledby="t-cierre">
    <div class="contenedor cierre__interior">
      <h2 class="h2 h2--grande" id="t-cierre">Contacto</h2>
      <p>¿A qué esperas para revolucionar tu sistema de atención al cliente?</p>
      <p>Si tienes dudas, quieres pedirnos más información o quieres comunicarte con nosotros… ¡Estaremos encantados de atenderte! <strong>¿Hablamos?</strong></p>
      <div class="hero__acciones" style="justify-content:center;">
        <a class="btn btn--primario btn--grande" href="https://dialogfy.com/solicitar-demo/">Reservar demo<svg aria-hidden="true" focusable="false"><use href="#i-flecha"/></svg></a>
        <a class="btn btn--secundario btn--grande" href="https://wa.me/34919049292" rel="noopener" style="color:#fff;border-color:rgba(255,255,255,.35);">Escríbenos por WhatsApp</a>
      </div>
      <p class="cierre__micro">De lunes a jueves de 9:00 a 18:00 · Viernes de 9:00 a 15:00 · <a href="tel:+34919049292" style="color:inherit;">+34 919 04 92 92</a></p>
    </div>
  </section>
</main>

@siteFooter

</body>
</html>
