# Contrato de la plantilla del blog

Cada cliente tiene una **plantilla**: dos vistas Blade que reproducen la cabecera, el pie y el estilo de su web, más dos parciales (cabecera y pie). El CMS rellena el contenido; la plantilla no hace nada más. Este documento es el contrato. `php artisan blog:validate-template {nombre}` comprueba casi todo lo de aquí.

## Dónde vive

```
publiblog/resources/views/blogs/{nombre}/
├── article.blade.php          un artículo           → /blog/{categoria}/mi-articulo
├── index.blade.php            los listados          → /blog/, /blog/pagina/2/, /blog/{categoria}/, /blog/{categoria}/pagina/2/
└── partials/
    ├── header.blade.php       cabecera (igual en todas las páginas)
    └── footer.blade.php       pie (igual en todas las páginas)
```

- `{nombre}`: solo `a-z`, `0-9`, `-` y `_` (por ejemplo `wedaje` o `dialogf`). Es el valor del campo «Plantilla» del blog.
- `article` e `index` son obligatorias; sin `index` el blog no se puede dar de alta.
- **`index` sirve el listado general y los de cada categoría**: es la misma vista (en el general `$category` es `null`).
- La cabecera y el pie van en `partials/header` y `partials/footer` y se colocan con **`@siteHeader`** y **`@siteFooter`** (una vez cada una, en `article` e `index`). Así el CMS puede copiarlos dentro de cada página (modo por defecto) o compartirlos en un único archivo (SSI, ver más abajo).
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
| `$article->body_html` | el cuerpo **ya saneado**; es lo único que se imprime sin escapar |
| `$seo->meta_title`, `$seo->meta_description`, `$seo->canonical` | SEO ya resuelto, con valores por defecto |
| `$blog->name`, `$blog->domain`, `$blog->base_url`, `$blog->locale` | datos del blog (sin barra final en `base_url`) |

### `index.blade.php`

| Variable | Contenido |
|---|---|
| `$articles` | lista de objetos con `->title`, `->url`, `->excerpt`, `->published_at`, `->featured_image`, `->category` (la principal) y `->categories` |
| `$category` | `null` en el listado general; en el de una categoría, `->name`, `->slug`, `->description` (puede ser `null`), `->url` |
| `$categories` | menú: las categorías que tienen artículos, por nombre, con `->name`, `->slug`, `->url` (sin contadores, para que un artículo nuevo no cambie las páginas de otras categorías) |
| `$pagination->current`, `->total` | página actual y número de páginas |
| `$pagination->prev_url`, `->next_url` | URL de la página anterior / siguiente, o `null` |
| `$seo`, `$blog` | igual que en el artículo |

El listado puede estar **vacío** y puede tener **una sola página**: la plantilla debe aguantar ambos casos. En el listado de una categoría el `<h1>` es el nombre de la categoría (`{{ $category ? $category->name : 'Blog' }}`).

### Cabecera y pie (`partials/header` y `partials/footer`)

Reciben **solo `$blog`**. Son iguales en todas las páginas: no pueden usar `$article`, `$seo`, `$category` ni nada que cambie de una página a otra (el validador lo comprueba). Son un fragmento: sin `<html>`, `<head>` ni `<body>`, y sin directivas SSI propias. Si el menú necesita marcar la sección activa, eso se hace con CSS o JavaScript de la web del cliente, no desde el parcial.

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
- Escribir rutas absolutas con el dominio del cliente: usa `$blog->base_url`, `$article->url`, `$category->url` o rutas desde la raíz.
- Meter lógica: bucles sobre el listado, condiciones de «si existe» y poco más.

## Flujo de trabajo

1. Copiar la carpeta de `demoblog` como punto de partida: `resources/views/blogs/demoblog` → `resources/views/blogs/{cliente}`.
2. Sustituir cabecera, pie y `<link>` de estilos por los de la web real en los parciales (o usar el [prompt para IA](prompt-plantilla-ia.md)).
3. Añadir al CSS del cliente (o a un `blog.css` propio) los estilos de `.post-body`, del listado, de las migas de pan y del menú de categorías.
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
4. Mientras no se regenere, el panel muestra un aviso: «La plantilla ha cambiado desde la última regeneración».

Los artículos con cambios **sin publicar** conservan su borrador: la regeneración solo toca lo que está publicado.

### Si el blog comparte cabecera y pie (SSI)

Con SSI activado, los artículos y los listados llevan `<!--#include virtual="/blog/_inc/header.html" -->` (y el del pie) en vez de la cabecera copiada. Entonces:

- Cambiar **solo la cabecera o el pie** (los parciales) y pulsar «Regenerar blog» sube **dos archivos** (`_inc/header.html` y `_inc/footer.html`) en vez de todos los artículos: los artículos no cambian.
- Cambiar cualquier otra cosa de las vistas (estructura, `<head>`, tarjetas…) se regenera como siempre.
- Para activarlo: el servidor del cliente necesita `mod_include` y que el `.htaccess` pueda usar `Options` (`AllowOverride Options`). El panel lo comprueba en una carpeta de pruebas antes de tocar nada; ver [alta-de-cliente.md](alta-de-cliente.md) §3.7.
- Los fragmentos son públicos (`/blog/_inc/header.html`); conviene añadir `Disallow: /blog/_inc/` al `robots.txt` de la web.
