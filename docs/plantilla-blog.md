# Contrato de la plantilla del blog

Cada cliente tiene una **plantilla**: dos vistas Blade que reproducen la cabecera, el pie y el estilo de su web, más dos parciales (cabecera y pie). El CMS rellena el contenido; la plantilla no hace nada más. Este documento es el contrato. `php artisan blog:validate-template {nombre}` comprueba casi todo lo de aquí.

## Dónde vive

```
publiblog/resources/views/blogs/{nombre}/
├── article.blade.php          un artículo           → /blog/{categoria}/mi-articulo
├── index.blade.php            los listados          → /blog/, /blog/pagina/2/, /blog/{categoria}/, /blog/{categoria}/pagina/2/
├── partials/
│   ├── header.blade.php       cabecera (igual en todas las páginas)
│   └── footer.blade.php       pie (igual en todas las páginas)
└── assets/                    opcional: el CSS propio del blog, tipografías, imágenes del diseño
    └── blog.css               → /blog/_assets/blog.css
```

- `{nombre}`: solo `a-z`, `0-9`, `-` y `_` (por ejemplo `wedaje` o `dialogf`). Es el valor del campo «Plantilla» del blog.
- `article` e `index` son obligatorias; sin `index` el blog no se puede dar de alta.
- **`index` sirve el listado general y los de cada categoría**: es la misma vista (en el general `$category` es `null`).
- La cabecera y el pie van en `partials/header` y `partials/footer` y se colocan con **`@siteHeader`** y **`@siteFooter`** (una vez cada una, en `article` e `index`). Así el CMS puede copiarlos dentro de cada página (modo por defecto) o compartirlos en un único archivo (SSI, ver más abajo).
- **`assets/`** (opcional) lleva los archivos de diseño propios del blog. El CMS los sube a `/blog/_assets/` con los listados, solo cuando cambian, y la plantilla los enlaza con `@templateAsset('blog.css')` (ver «Estilos propios del blog»).
- La plantilla es **código del repositorio**: se revisa, se prueba y se despliega con el resto. No se edita desde el panel.

## Direcciones (URL)

| Qué | Dirección | Archivo en el servidor |
|---|---|---|
| Artículo | `/blog/{categoria-principal}/{slug}` | `{categoria-principal}/{slug}.html` |
| Listado general | `/blog/` y `/blog/pagina/2/` | `index.html`, `pagina/2/index.html` |
| Listado de una categoría | `/blog/{categoria}/` y `/blog/{categoria}/pagina/2/` | `{categoria}/index.html`, … |

Un artículo puede estar en varias categorías; la **principal** (la primera) forma parte de su URL y las demás solo lo añaden a sus listados, enlazando siempre a la URL de la principal. Los enlaces dentro de la plantilla usan `->url` (completo) y nunca se construyen a mano.

## Qué recibe cada vista

### `article.blade.php`

| Variable | Contenido |
|---|---|
| `$article->public_id` | ID de 6 dígitos (`000123`) |
| `$article->title` | título (texto, hay que escaparlo: `{{ }}`) |
| `$article->slug`, `$article->url` | slug y URL pública completa (con la categoría principal) |
| `$article->excerpt` | extracto (si el editor no lo escribió, los primeros 160 caracteres) |
| `$article->published_at`, `$article->updated_at` | fechas (Carbon, ya en la zona horaria e idioma del blog) |
| `$article->author` | `null` o un objeto con `->name` |
| `$article->featured_image` | `null` o un objeto con `->url` (`/blog/img/2026/…`), `->alt`, `->width`, `->height` |
| `$article->category` | la categoría principal: `->name`, `->slug`, `->url` (o `null` en artículos muy antiguos) |
| `$article->categories` | lista de todas sus categorías (la primera es la principal), con `->name`, `->slug`, `->url` |
| `$article->reading_minutes` | tiempo de lectura en minutos (entero, mínimo 1; 200 palabras por minuto) |
| `$article->word_count` | número de palabras del cuerpo |
| `$article->toc` | índice de contenidos: lista de los `<h2>` del cuerpo, cada uno con `->id` (el ancla), `->text` (texto plano, hay que escaparlo) y `->level` (2). Vacía si no hay apartados |
| `$article->body_html` | el cuerpo **ya saneado**; es lo único que se imprime sin escapar. Cada `<h2>` y `<h3>` lleva un `id` calculado de su texto (`que-es-un-agente`, único en el artículo) para poder enlazarlos; el editor no escribe ids |
| `$seo->meta_title`, `$seo->meta_description`, `$seo->canonical` | SEO ya resuelto, con valores por defecto |
| `$blog->name`, `$blog->domain`, `$blog->base_url`, `$blog->locale` | datos del blog (sin barra final en `base_url`) |

### `index.blade.php`

| Variable | Contenido |
|---|---|
| `$articles` | lista de objetos con `->title`, `->url`, `->excerpt`, `->published_at`, `->featured_image`, `->category` (la principal), `->categories` y `->reading_minutes` (**puede ser `null`** en artículos publicados antes de existir el dato, hasta que se regenera el blog: usa `@if($item->reading_minutes)`) |
| `$category` | `null` en el listado general; en el de una categoría, `->name`, `->slug`, `->description` (puede ser `null`), `->url` |
| `$categories` | menú: las categorías que tienen artículos, por nombre, con `->name`, `->slug`, `->url` (sin contadores, para que un artículo nuevo no cambie las páginas de otras categorías) |
| `$pagination->current`, `->total` | página actual y número de páginas |
| `$pagination->prev_url`, `->next_url` | URL de la página anterior / siguiente, o `null` |
| `$pagination->pages` | números de página para pintar «1 … 4 5 **6** 7 8 … 20» (la primera, la última y dos a cada lado de la actual). Cada elemento: `->gap` (`true` = «…»), `->number`, `->url`, `->current` |
| `$seo`, `$blog` | igual que en el artículo |

**Índice de contenidos.** Para pintarlo: `@if(count($article->toc) > 1)` … `@foreach($article->toc as $h)<a href="#{{ $h->id }}">{{ $h->text }}</a>@endforeach`. Si el artículo tiene menos de dos apartados la plantilla no debería mostrar el índice (y su maquetación a dos columnas debe tener una variante de una).

**Lo que el editor no puede producir.** El editor solo genera las etiquetas listadas en «Lo que el editor puede producir»: no hay clases propias, tarjetas, cajas de «ventajas» ni listas con iconos. Un post de diseño especial se reproduce con CSS sobre lo que sí hay (`h3 + p`, `ul`, el primer `<p>` como entradilla con `.cuerpo > p:first-child`…).

El listado puede estar **vacío** y puede tener **una sola página**: la plantilla debe aguantar ambos casos. En el listado de una categoría el `<h1>` es el nombre de la categoría (`{{ $category ? $category->name : 'Blog' }}`).

### Cabecera y pie (`partials/header` y `partials/footer`)

Reciben **solo `$blog`**. Son iguales en todas las páginas: no pueden usar `$article`, `$seo`, `$category` ni nada que cambie de una página a otra (el validador lo comprueba). Son un fragmento: sin `<html>`, `<head>` ni `<body>`, y sin directivas SSI propias. Si el menú necesita marcar la sección activa, eso se hace con CSS o JavaScript de la web del cliente, no desde el parcial.

## Directivas del CMS

Son lo único «especial» que puede usar una plantilla, además de `@if`, `@foreach` y `@forelse`:

| Directiva | Qué hace | Dónde |
|---|---|---|
| `@siteHeader` / `@siteFooter` | colocan la cabecera y el pie (copiados en la página o, con SSI, como una inclusión) | una vez cada una, en `article` e `index` |
| `@templateAsset('blog.css')` | dirección del archivo `assets/blog.css` de la plantilla, con su huella: `/blog/_assets/blog.css?v=1a2b3c4d` | en un `href`/`src`; el nombre va entre comillas y el archivo debe existir |
| `@structuredData` | datos estructurados (JSON-LD de schema.org) de la página, ya escapados | **una vez, dentro de `<head>`** |

`@structuredData` genera: en el artículo `BlogPosting` (titular, fechas, imagen, autor, categoría, palabras) y migas `BreadcrumbList`; en el listado general `Blog` con sus artículos, y en el de una categoría `CollectionPage`. La plantilla no escribe JSON-LD a mano: un título con comillas o con `</script>` no puede romper la página.

## Estilos propios del blog (`assets/`)

Los estilos de la web del cliente (su `styles.css`) ya están en su servidor y se enlazan con su ruta (`https://cliente.com/styles.css` o `/styles.css`). Lo que es **solo del blog** (listado, tarjetas, paginación, `.post-body`, migas, menú de categorías) va en `assets/blog.css` de la plantilla y lo publica el CMS:

- Se sube a `/blog/_assets/` junto con los listados, **solo si cambió** (mismo control por huella que los listados). Si se borra de la plantilla, desaparece del servidor.
- El enlace lleva `?v={huella}`: al cambiar el CSS cambia el enlace y ni los navegadores ni Cloudflare sirven la versión vieja. Como el enlace está dentro de cada página, cambiar el CSS pide **«Regenerar blog»** (vuelve a generar los artículos con el enlace nuevo y sube el archivo).
- Solo se publican archivos de diseño: `css`, `js`, `png`, `jpg`, `gif`, `webp`, `svg`, `ico`, `woff`, `woff2`; nombres sin espacios ni caracteres raros, sin archivos ocultos y hasta 5 MB. Cualquier otra cosa (`.php`, `.html`, `.htaccess`…) no se sube y el validador lo avisa.
- Una referencia a un archivo que no existe hace fallar la generación (y el validador): nunca sale una página sin sus estilos.

## Reglas obligatorias

**En el `<head>` del artículo y de los listados**

- `<html lang="{{ $blog->locale }}">`
- `<meta charset="utf-8">` y `<meta name="viewport" content="width=device-width, initial-scale=1">`
- `<title>{{ $seo->meta_title }}</title>`
- `<meta name="description" content="{{ $seo->meta_description }}">`
- `<link rel="canonical" href="{{ $seo->canonical }}">`
- Solo en el **artículo**: `<meta name="blog-article-id" content="{{ $article->public_id }}">` — el CMS lo usa para comprobar que el artículo se publicó bien.
- En los listados: `rel="prev"` / `rel="next"` si existen `$pagination->prev_url` / `next_url`, y enlaces de paginación visibles que lleguen a la página siguiente.

**En el cuerpo**

- Exactamente **un** `<h1>` por página (en el artículo, el título; en los listados, el nombre de la categoría o el título del blog). Los `<h2>` de cada tarjeta del listado están bien.
- `@siteHeader` y `@siteFooter`, una vez cada uno por vista, en lugar de copiar la cabecera y el pie. (Un `@include` de los parciales sigue funcionando, pero el blog no podrá compartir la cabecera por SSI.)
- El cuerpo del artículo se imprime con `{!! $article->body_html !!}`. **Ninguna otra** salida puede ir sin escapar (`{!! !!}`): el resto, siempre con `{{ }}`. Los nombres de categoría también se escapan.
- Nada de PHP suelto: ni `<?php`, ni `@php`.
- Las hojas de estilo, fuentes e imágenes del sitio se enlazan con ruta absoluta desde la raíz de la web (`/css/web.css`, `/img/logo.svg`). La plantilla **no sube** esos archivos: ya están en la web del cliente.
- Cabecera y pie se copian de la web real. Si en la web del cliente cambian, hay que actualizar los parciales y regenerar el blog (ver abajo).

**Lo que el editor puede producir en `body_html`** (el CSS de la plantilla debe contemplarlo todo, dentro de un contenedor propio, p. ej. `.post-body`):

`p`, `h2`–`h4`, `strong`, `em`, `ul`, `ol`, `li`, `a`, `img`, `table` (con `thead`, `tbody`, `tr`, `th`, `td`), `blockquote`, `br`, `hr`, `figure`, `figcaption`.

No existen `script`, `style`, `iframe`, formularios ni atributos `on*`: no hace falta estilar nada de eso.

## Qué no debe hacer una plantilla

- Cargar scripts de terceros que no tenga ya la web del cliente.
- Depender de variables que no están en las tablas de arriba (el validador las detecta como «sin resolver»).
- Escribir rutas absolutas con el dominio del cliente **para los enlaces del propio blog**: usa `$blog->base_url`, `$article->url`, `$category->url` o rutas desde la raíz. Los enlaces de la cabecera, el pie y los estilos de la web del cliente se copian tal cual de su web (con su dominio, si lo tienen), y así la plantilla se puede probar en otro dominio antes de salir.
- Enlazar `blog.css` con una ruta relativa (`href="blog.css"`): en `/blog/{categoria}/{slug}` no resolvería. Usa `@templateAsset`.
- Escribir JSON-LD a mano: usa `@structuredData`.
- Meter lógica: bucles sobre el listado, condiciones de «si existe» y poco más.

## Flujo de trabajo

1. Copiar la carpeta de `demoblog` como punto de partida: `resources/views/blogs/demoblog` → `resources/views/blogs/{cliente}`.
2. Sustituir cabecera, pie y `<link>` de estilos por los de la web real en los parciales (o usar el [prompt para IA](prompt-plantilla-ia.md)).
3. Poner en `assets/blog.css` los estilos de `.post-body`, del listado, de las migas de pan y del menú de categorías, y enlazarlo con `@templateAsset('blog.css')`. (Alternativa: que el equipo de la web los añada a su CSS, como hace `demoblog` con `/css/blog.css`.)
4. Validar:
   ```bash
   php artisan blog:validate-template {cliente}
   ```
   Debe terminar sin errores.
5. Dar de alta el blog en el panel con esa plantilla, crear sus categorías y publicar un artículo de prueba con título largo, imagen, tabla y lista. Abrirlo en móvil y en escritorio.
6. Comparar con la web real: cabecera, pie, tipografías y anchos. «Se ve igual que el resto de la web» es el criterio de aceptación.

## Cambiar una plantilla que ya está en producción

Los artículos publicados son archivos HTML ya generados: **no cambian solos** al tocar la plantilla.

1. Desplegar el cambio y limpiar las vistas compiladas: `php artisan view:clear`.
2. En el panel: **Blog → «Regenerar blog»**. Vuelve a generar todos los artículos publicados con la plantilla nueva y sube solo los archivos que cambian (barra de progreso; lo procesa el cron).
3. Si solo cambió un listado (no los artículos), la regeneración sube únicamente los listados.
4. Si cambió un archivo de `assets/` (p. ej. `blog.css`), cambia su huella y por tanto el enlace de **todas** las páginas: se regeneran los artículos (con el enlace nuevo) y se sube el archivo.
5. Mientras no se regenere, el panel muestra un aviso: «La plantilla ha cambiado desde la última regeneración».

Los artículos con cambios **sin publicar** conservan su borrador: la regeneración solo toca lo que está publicado.

### Si el blog comparte cabecera y pie (SSI)

Con SSI activado, los artículos y los listados llevan `<!--#include virtual="/blog/_inc/header.html" -->` (y el del pie) en vez de la cabecera copiada. Entonces:

- Cambiar **solo la cabecera o el pie** (los parciales) y pulsar «Regenerar blog» sube **dos archivos** (`_inc/header.html` y `_inc/footer.html`) en vez de todos los artículos: los artículos no cambian.
- Cambiar cualquier otra cosa de las vistas (estructura, `<head>`, tarjetas…) se regenera como siempre.
- Para activarlo: el servidor del cliente necesita `mod_include` y que el `.htaccess` pueda usar `Options` (`AllowOverride Options`). El panel lo comprueba en una carpeta de pruebas antes de tocar nada; ver [alta-de-cliente.md](alta-de-cliente.md) §3.7.
- Los fragmentos son públicos (`/blog/_inc/header.html`); conviene añadir `Disallow: /blog/_inc/` al `robots.txt` de la web.
