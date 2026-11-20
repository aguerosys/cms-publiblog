# Cambio de un blog de WordPress al CMS

Guía para sustituir un blog que hoy es un WordPress dentro de `/blog/` (caso de Dialogfy) por Publiblog **sin perder posicionamiento**. El CMS y WordPress no pueden convivir en la misma carpeta: hay un corte, que se prepara para que dure minutos.

## Qué cubre el CMS y qué no

| URL antigua de WordPress | ¿Se redirige sola? |
|---|---|
| Entrada `/blog/mi-articulo/` (y variantes dentro de `/blog/`, p. ej. `/blog/2021/05/mi-articulo/`) | **Sí**: al publicar lo importado se escribe un 301 a `/blog/{categoria}/mi-articulo` |
| Entrada fuera de `/blog/` (`/mi-articulo/` en la raíz del dominio) | No: lo gestiona la web del cliente (el CSV de la importación es la tabla de equivalencias) |
| Categorías `/blog/category/{slug}/` | **No** (hoy). Equivale a `/blog/{slug}/` |
| Paginación `/blog/page/2/` | **No** (hoy). Equivale a `/blog/pagina/2/` |
| Etiquetas `/blog/tag/…`, autores `/blog/author/…`, feeds `/blog/feed/` | No. Decidir a dónde van (normalmente al listado `/blog/`) |
| Imágenes `/blog/wp-content/uploads/…` | **No se tocan**: se conservan como archivos estáticos (ver «Imágenes») |
| Sitemap de Yoast (`/blog/sitemap_index.xml`, `/blog/post-sitemap.xml`…) | No: se sustituye por `/blog/sitemap.xml` y se avisa a Search Console |

Las filas «No (hoy)» se resuelven con unas pocas reglas genéricas de tamaño fijo en el `.htaccess` del blog (no crecen con el número de artículos). Es un trabajo pequeño pendiente (ver «Decisiones abiertas»).

## Fase 1 · Ensayo en `demoblog` (sin tocar la web real)

1. En el CMS, usar la plantilla `dialogfy` en el blog de pruebas (`demoblog.publiup.com`): sus estilos generales los carga desde `https://dialogfy.com/styles.css`, así que se ve igual que la web real.
2. Crear las categorías del blog. Conviene que el **slug** de cada una sea el de WordPress (`/blog/category/{slug}/` → `/blog/{slug}/`); el importador crea las que falten con el slug de WordPress.
3. Exportar de WordPress (Herramientas → Exportar → Entradas) e importar:
   ```
   php artisan blog:import-wordpress {blog} export.xml --dry-run
   php artisan blog:import-wordpress {blog} export.xml --images-dir=/ruta/wp-content/uploads
   ```
   Revisar el **informe CSV** (avisos, imágenes que no se pudieron traer, entradas sin categoría).
4. Revisar los borradores, publicar los que estaban publicados (`--publish`) y comparar con la web real: cabecera, pie, listado, un artículo con tabla/lista/imagen, móvil y escritorio.
5. Ejecutar `blog:verify` y abrir una muestra de URLs (artículo, categoría, `/blog/pagina/2/`, sitemap).

## Fase 2 · Inventario de la web real (antes del corte)

- Lista de todas las URL que dan tráfico o enlaces: sitemap de Yoast + Search Console (Rendimiento → Páginas) + Analytics.
- `grep` en el resto de la web de `wp-content/uploads` y `/blog/` (imágenes de portada, `og:image`, enlaces internos a entradas). Esas referencias hay que revisarlas.
- Copia de seguridad completa: archivos de `/blog/` y base de datos de WordPress. Se conserva 30 días.
- Decidir las filas «No» de la tabla de arriba (categorías, páginas, etiquetas, feed).

## Fase 3 · El corte (ventana corta)

Orden pensado para que la web no quede sin blog más que unos minutos:

1. Aviso interno y exportación final de WordPress (por si hubo entradas nuevas desde el ensayo).
2. En el servidor del cliente, **mover WordPress fuera de la carpeta del blog**: renombrar `/blog/` a `/blog-wp-copia/` y crear una `/blog/` nueva con solo `wp-content/uploads/` copiado dentro (las imágenes antiguas siguen sirviéndose como estáticas). No dejar el `.htaccess` de WordPress ni sus `.php` en `/blog/`.
3. Dar de alta el blog real en el CMS (SFTP, ruta `/blog`), importar y publicar (`--publish`). El CMS escribe sus páginas, el sitemap y las redirecciones 301 de las entradas.
4. Comprobar: `blog:verify`; 20 URLs antiguas devuelven 301 a la nueva; el listado, una categoría, `/blog/sitemap.xml` y un artículo responden 200 con cabecera, pie y estilos.
5. Añadir las redirecciones de categorías, paginación, etiquetas y feed (si se decidió).
6. Search Console: enviar `/blog/sitemap.xml` y retirar el sitemap de Yoast. Actualizar `robots.txt` si apunta al antiguo.

**Plan B** (si algo grave falla): renombrar `/blog/` a `/blog-cms/` y `/blog-wp-copia/` de vuelta a `/blog/`. WordPress sigue intacto.

## Fase 4 · Después

- 2–4 semanas vigilando los 404 (Search Console → Páginas → «No encontrada» y los logs del servidor) y añadiendo la redirección que falte.
- Cuando todo está estable, borrar `/blog-wp-copia/` (no antes de 30 días).
- Regenerar el blog (`Regenerar blog`) cada vez que cambie la plantilla o su `blog.css`.

## Imágenes

El importador descarga o copia las imágenes de cada entrada a `/blog/img/…` y reescribe sus enlaces. Aun así **no se borra `wp-content/uploads`**: otras páginas de la web, correos o webs externas pueden enlazarlas. Al ser archivos existentes, Apache los sirve tal cual sin reglas extra.

## Decisiones abiertas y riesgos

- **Reglas genéricas de WordPress** (`category/`, `page/`, `feed/`, `tag/`): pendientes de implementar como una opción del blog con reglas fijas.
- **Título y descripción SEO del listado**: hoy se generan solos («Dialogfy», «Artículos de Dialogfy»). La web real tiene unos propios («Blog de atención al cliente, WhatsApp e IA | Dialogfy»): conviene poder configurarlos por blog antes del corte.
- **Páginas ajenas al blog que dependen de WordPress** (formularios, widgets, `wp-json`, RSS en el pie, plugins de cookies/analítica del blog): inventariarlas en la fase 2.
- **Cloudflare**: si hay WAF delante, la comprobación HTTP del CMS puede recibir un 403; permitir la IP del CMS o desactivar «Verificar la URL pública» en ese blog.
- **Entradas con shortcodes o maquetadores** (Elementor, Divi): el importador limpia Gutenberg y shortcodes habituales y lo anota en el CSV, pero el diseño de esas entradas se reconstruye en el editor.
