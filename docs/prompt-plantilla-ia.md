# Prompt para crear la plantilla de un blog con IA

Sirve para webs nuevas: se le da a una IA (Claude, ChatGPT…) el HTML de una página real del cliente y devuelve las vistas Blade del blog (artículo, listado, cabecera y pie). **Siempre se revisa** el resultado y se pasa por `php artisan blog:validate-template`.

## Qué preparar antes

1. El HTML completo de **una página interior** de la web del cliente (la que tenga cabecera, pie y un bloque de texto). Se guarda con «Ver código fuente», no con «Guardar página como».
2. Si el diseño depende de un CSS externo, la lista de `<link>` y `<script>` que hay en esa página.
3. El nombre de la plantilla (`dialogf`, `wedaje`…), el idioma (`es`) y el dominio.

## El prompt

Copia desde aquí. Sustituye lo que está entre `<MAYÚSCULAS>` y pega el HTML al final. Las `{{ llaves dobles }}` del texto son Blade y se dejan tal cual.

````text
Eres un desarrollador front-end. Tengo que crear la plantilla de blog de una web que ya existe.
Te paso el HTML de una página de esa web. Devuélveme los archivos Blade completos que reproduzcan
exactamente su cabecera, su pie y su estilo, para un blog que genera páginas HTML estáticas.

DATOS
- Nombre de la plantilla: <NOMBRE>
- Dominio: <DOMINIO>
- Idioma: <IDIOMA>

ARCHIVOS A ENTREGAR
1. resources/views/blogs/<NOMBRE>/article.blade.php            (un artículo)
2. resources/views/blogs/<NOMBRE>/index.blade.php              (listado de artículos con paginación; sirve el listado general y el de cada categoría)
3. resources/views/blogs/<NOMBRE>/partials/header.blade.php    (SOLO la cabecera de la web: el <header> y su menú)
4. resources/views/blogs/<NOMBRE>/partials/footer.blade.php    (SOLO el pie de la web)
5. resources/views/blogs/<NOMBRE>/assets/blog.css               (el CSS propio del blog, ver más abajo)
La cabecera y el pie van SIEMPRE en esos dos parciales y se colocan en article e index con las directivas
@siteHeader y @siteFooter (una vez cada una, justo donde iría la cabecera y el pie). NO uses @include para ellos.
Si el HTML de la web trae un sprite de iconos SVG, el "saltar al contenido" o el banner de cookies y sus <script> finales,
cada cosa va en el parcial al que pertenece (sprite y "saltar al contenido" en la cabecera; banner de cookies y scripts en el pie).

VARIABLES DISPONIBLES (no uses ninguna otra)
En article.blade.php:
  $article->title, ->public_id, ->slug, ->url, ->excerpt
  $article->published_at (Carbon; usa ->toIso8601String() para datetime y ->translatedFormat('j \d\e F \d\e Y') para mostrarla)
  $article->author            (null o un objeto con ->name)
  $article->featured_image    (null o un objeto con ->url, ->alt, ->width, ->height)
  $article->category          (la categoría principal: ->name, ->url; puede ser null)
  $article->categories        (lista de todas sus categorías: ->name, ->url)
  $article->reading_minutes   (entero ≥ 1: tiempo de lectura en minutos)
  $article->toc               (índice de contenidos: lista de los <h2> del cuerpo con ->id (ancla), ->text y ->level; los <h2>/<h3>
                               del cuerpo ya llevan su id. Muestra el índice solo si count($article->toc) > 1)
  $article->body_html         (HTML ya saneado)
  $seo->meta_title, $seo->meta_description, $seo->canonical
  $blog->name, $blog->domain, $blog->base_url, $blog->locale
En index.blade.php:
  $articles (lista; cada $item tiene ->title, ->url, ->excerpt, ->published_at, ->featured_image, ->category (name, url; puede ser null),
             ->reading_minutes (entero o null: usa @if($item->reading_minutes)))
  $category   (null en el listado general; en el de una categoría: ->name, ->description (puede ser null), ->url, ->slug)
  $categories (menú de categorías: lista de objetos con ->name, ->url, ->slug; puede estar vacía)
  $pagination->current, ->total, ->prev_url, ->next_url   (prev_url / next_url pueden ser null)
  $pagination->pages  (para "1 … 4 5 [6] 7 8 … 20": lista de objetos con ->gap (true = "…"), ->number, ->url, ->current)
  $seo, $blog (igual que arriba)
En partials/header.blade.php y partials/footer.blade.php:
  SOLO $blog (->name, ->domain, ->base_url, ->locale). Son idénticos en todas las páginas: NO pueden usar $article,
  $seo, $category ni nada que cambie de una página a otra. Son fragmentos: sin <html>, <head> ni <body>.

REGLAS OBLIGATORIAS
- article e index empiezan por <!doctype html> y <html lang="{{ $blog->locale }}">.
- En el <head> de ambos archivos: <meta charset="utf-8">, <meta name="viewport" content="width=device-width, initial-scale=1">,
  <title>{{ $seo->meta_title }}</title>, <meta name="description" content="{{ $seo->meta_description }}">
  y <link rel="canonical" href="{{ $seo->canonical }}">.
- Solo en article.blade.php, en el <head>: <meta name="blog-article-id" content="{{ $article->public_id }}">
  Además <meta property="og:title|og:description|og:url|og:type">, y og:image si existe featured_image
  (URL completa: {{ $blog->base_url }}{{ $article->featured_image->url }}).
- En el listado, si existen: <link rel="prev" href="..."> y <link rel="next" href="...">.
- Exactamente UN <h1> por archivo: el título del artículo en article; en index, el nombre de la categoría si
  existe $category y "Blog" (o el título que use la web) si no.
- En article, si existe $article->category, muestra unas migas de pan (Blog › Categoría) y enlaza las categorías del artículo.
- En index, si existe $category con descripción, muéstrala bajo el título. Si $categories no está vacía, muestra un menú
  de categorías (con "Todas" que lleva a /blog/) y marca la actual con aria-current="page".
- En las tarjetas del listado, si existe $item->category, muestra su nombre enlazado.
- Imprime el cuerpo con {!! $article->body_html !!} y NUNCA uses {!! !!} para nada más. Todo lo demás con {{ }}.
- Prohibido PHP suelto: nada de <?php ni @php. Solo se permiten @if, @foreach/@forelse, @siteHeader, @siteFooter,
  @templateAsset('archivo'), @structuredData y variables.
- En el <head> de article e index, una sola vez, pon @structuredData (el CMS genera los datos estructurados JSON-LD:
  si la página original trae un <script type="application/ld+json">, NO lo copies, sustitúyelo por @structuredData).
- El CSS propio del blog va en assets/blog.css y se enlaza con <link rel="stylesheet" href="@templateAsset('blog.css')">
  (NUNCA con una ruta relativa como href="blog.css"). Los estilos generales de la web (su styles.css) se enlazan tal cual vienen.
- Mantén las rutas y enlaces de la cabecera, el pie y los estilos de la web tal cual vienen (con su dominio si lo llevan).
  Los enlaces del propio blog (volver al listado, categorías, artículos) usan /blog/, $category->url, $item->url… nunca el dominio a mano.
- Dentro de un atributo, escribe & como &amp; (p. ej. en la URL de Google Fonts).
- No cargues scripts ni estilos nuevos que no estén ya en la página que te paso. No inventes JavaScript.
- Conserva la cabecera, el menú y el pie con sus mismos enlaces, clases y estructura. No "mejores" el diseño.
- El cuerpo del artículo SOLO puede contener p, h2-h4, strong, em, ul, ol, li, a, img, table, blockquote, br, hr, figure
  y figcaption, sin clases ni ids (el editor no los escribe). Si el post de la web usa bloques especiales (tarjetas, cajas,
  listas con iconos, una clase para la entradilla), reprodúcelos con CSS sobre esas etiquetas (p. ej. el primer <p> como
  entradilla con ".cuerpo > p:first-child") y dime qué elementos no se pueden reproducir. Un índice de contenidos
  "con anclas" sale de $article->toc, no se escribe a mano.
- El cuerpo del artículo irá dentro de un contenedor con la clase "post-body". Dame también el CSS (para un archivo
  blog.css) que estile dentro de ese contenedor: p, h2, h3, h4, strong, em, ul, ol, li, a, img (max-width:100%; height:auto),
  table (con th/td), blockquote, hr, figure y figcaption, usando la tipografía, colores y anchos de la web. Añade también
  los estilos de las migas de pan (.breadcrumb) y del menú de categorías (.cats).
- El listado debe funcionar con 0 artículos ("Todavía no hay artículos") y con una sola página (sin paginación).
- La paginación se pinta con $pagination->pages (números, el actual con aria-current="page", "…" en los huecos) y
  enlaces anterior/siguiente solo si existen. Solo se muestra si $pagination->total > 1.
- Todo el HTML debe ser válido y accesible: alt en imágenes, <time datetime>, <nav aria-label="Paginación">.

SALIDA
Entrega cada archivo en su propio bloque de código con su ruta como título, y al final el CSS de blog.css.
Si algo del HTML original no se puede reproducir con estas reglas, dímelo en una lista al final en lugar de saltarte una regla.

HTML DE LA PÁGINA DE LA WEB:
<PEGA AQUÍ EL HTML COMPLETO>
````

## Qué hacer con la respuesta

1. Guardar los archivos en `publiblog/resources/views/blogs/{nombre}/` y el CSS donde corresponda en la web del cliente (lo sube el equipo de la web, el CMS no lo toca).
2. Ejecutar:
   ```bash
   php artisan blog:validate-template {nombre}
   ```
   Si da errores, pegárselos a la IA tal cual: «el validador dice esto, corrígelo respetando las reglas».
3. Revisar a mano lo que el validador **no** comprueba:
   - [ ] Cabecera, menú y pie idénticos a la web real (enlaces incluidos).
   - [ ] No se ha colado ningún `<script>` ni CSS que la web no tuviera.
   - [ ] Las rutas de CSS e imágenes son de la raíz (`/css/…`), sin el dominio.
   - [ ] Un artículo con tabla, lista, imagen y título muy largo se ve bien en móvil.
   - [ ] La paginación aparece con varias páginas y desaparece con una.
   - [ ] El listado de una categoría y el menú de categorías se ven bien (con 0, 1 y muchas categorías).
4. Seguir [alta-de-cliente.md](alta-de-cliente.md) para dar de alta el blog, crear sus categorías y publicar la prueba.
