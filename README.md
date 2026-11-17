# Publiblog

Gestor de blogs HTML estáticos. Un panel central en Laravel convierte un formulario en HTML y lo sube por SFTP a la carpeta `/blog/` de las webs de los clientes; la web del cliente no usa PHP ni base de datos. Plan técnico completo: *Plan técnico · Publiblog* (PDF).

```
publiblog/            monorepo
├── publiblog/        el CMS (Laravel 13, MySQL, Blade, Tiptap)
└── demoblog/         web cliente de prueba, 100 % estática (HTML + CSS)
    └── blog/         lo único que el CMS escribe (su contenido está en .gitignore)
```

## Puesta en marcha (local)

Requisitos: PHP ≥ 8.3 con `gd`, `exif`, `fileinfo`, `pdo_mysql`, `sodium`; Composer; Node ≥ 20; MySQL.

```bash
cd publiblog
composer install
cp .env.example .env && php artisan key:generate
# en .env: credenciales MySQL y PUBLIBLOG_ADMIN_EMAIL / PUBLIBLOG_ADMIN_PASSWORD
mysql -uroot -e "CREATE DATABASE publiblog CHARACTER SET utf8mb4; CREATE DATABASE publiblog_test CHARACTER SET utf8mb4;"
php artisan migrate --seed      # crea el superadmin y, fuera de producción, el blog «Demoblog (local)»
npm install && npm run build
```

Dos procesos para probar el flujo completo (desde la raíz del repo y desde `publiblog/`):

```bash
php -S localhost:8081 -t demoblog demoblog/router.php   # web cliente (emula las reglas .htaccess)
php artisan serve --port=8000                           # CMS → http://localhost:8000
```

El blog sembrado usa el transporte `local` (solo desarrollo): «publica» copiando a `demoblog/blog/` en vez de usar SFTP, y la verificación HTTP se hace contra `localhost:8081`. En producción el transporte es SFTP y `local` está bloqueado.

```bash
php artisan test                         # 200+ pruebas (MySQL, base publiblog_test)
php artisan blog:validate-template demoblog
php artisan blog:reindex {blog_id}       # reconstruye articles y media desde los archivos del CMS
php artisan blog:regenerate {blog_id}    # restaura: regenera y vuelve a subir TODO (tras recuperar la web del cliente)
php artisan blog:regenerate {id} --changed   # tras cambiar la plantilla: solo los archivos que cambian
php artisan blog:import-wordpress {id} export.xml [--dry-run|--publish]   # ver «Importar de WordPress»
php artisan blog:verify [{id}]           # compara el servidor del cliente con el CMS
php artisan blog:clean-media [--dry-run] # borra imágenes que ningún artículo usa
```

## Cómo funciona (fase 1)

1. **Alta de blog** (`/blogs`): dominio, URL base, plantilla y datos SFTP. El secreto va cifrado (`encrypted`) y no se puede volver a leer, solo reemplazar. «Probar conexión» muestra la huella del servidor para confirmarla la primera vez; si cambia, se bloquea la publicación hasta que un administrador la confirme.
2. **Artículo**: «Nuevo artículo» abre un borrador con su ID de 6 dígitos. El editor (Tiptap) entrega HTML, que se sanea con HTMLPurifier al guardar y otra vez al generar. Las imágenes se recodifican con GD (sin EXIF), máx. 1.600 px.
3. **Guardado** (`storage/app/blogs/{blog}/articles/{shard}/{id}.json|html`): JSON → HTML → fila de `articles`, con escritura atómica. Es la fuente de verdad.
4. **Publicar** (`PublishArticle`, `dispatchSync` en fase 1): bloqueo por blog → huella → imágenes nuevas → HTML → bloque de `.htaccess` (con copia `.bak`) → verificación de la URL pública (200 + meta `blog-article-id`). Si falla, el contenido queda guardado, el artículo pasa a `error` y «Reintentar» repite todo (los pasos remotos son idempotentes).
5. **Editar** = cargar el JSON y sobrescribir; si la `version` cambió, avisa antes de pisar. **Eliminar** = quitar la regla, borrar el archivo remoto y conservar el JSON.

URLs: `/blog/{categoria}/{slug}` se sirve desde el archivo `/blog/{categoria}/{slug}.html` (ver *Categorías* más abajo). El `.htaccess` lleva un bloque entre `# BEGIN publiblog` y `# END publiblog` de tamaño casi fijo; el resto del archivo no se toca. *(Hasta la versión anterior cada artículo era `/blog/000123-slug.html` con una regla propia; esos artículos siguen funcionando y se trasladan a su categoría al volver a publicarlos.)*

## Listado y sitemap (fase 1b)

Tras cada publicación o eliminación se regeneran `/blog/` (paginado: `index.html`, `pagina/N/index.html`, sin reglas `.htaccess`), el listado de cada categoría afectada y `sitemap.xml` (se parte en `sitemap-N.xml` con un índice al pasar de 500 URLs; configurable con `PUBLIBLOG_SITEMAP_LIMIT`). Solo se suben los archivos cuyo hash cambió (manifiesto en `storage/app/blogs/{blog}/build/`). La plantilla necesita `index.blade.php` además de `article.blade.php`.

## Plantillas

Vistas Blade en `resources/views/blogs/{plantilla}/` (`article.blade.php`, `index.blade.php` + parciales). Solo ven `$article` / `$articles` + `$pagination`, `$seo`, `$blog`, `$category` y `$categories` (contrato en `docs/plantilla-blog.md`). La cabecera y el pie se colocan con `@siteHeader` y `@siteFooter`. `php artisan blog:validate-template {plantilla}` comprueba etiquetas obligatorias, escape, ausencia de PHP suelto y de `{!! !!}` que no sea `body_html`.

## Probar SFTP real en local

`tools/sftp-test-server/` es un servidor SFTP de prueba, confinado a una carpeta y estricto como OpenSSH (ver su README). Sirve para probar huella, credenciales, cambio de huella, caída del servidor y reintentos sin tocar ningún hosting.

## Despliegue (Plesk, propuesta del plan)

```bash
git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

- La raíz web debe apuntar a `publiblog/public/`. `storage/` y `.env` fuera de la raíz web.
- `public/build` está en `.gitignore`: ejecutar `npm run build` en local/CI y subir esa carpeta (o tener Node en el servidor).
- `.env` de producción: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES="*"` (Cloudflare), `CACHE_STORE=database`.
- Copias de seguridad: base de datos, `storage/app` y `.env` **con la `APP_KEY`** (sin ella no se pueden descifrar las credenciales SFTP).
- Con `php artisan config:cache`, `TRUSTED_PROXIES` se lee de `config/publiblog.php` (no uses `env()` fuera de `config/`).
- Logs: los fallos de publicación se registran sin traza ni credenciales. Con `zend.exception_ignore_args=On` (por defecto en `php.ini-production`) tampoco hay argumentos en trazas de errores inesperados.
- Pide a Nacho que `/blog/` no ejecute PHP en el servidor del cliente (defensa adicional; no se fuerza desde el `.htaccess` por si el hosting no permite `AllowOverride AuthConfig`).
- `php.ini`: `upload_max_filesize` y `post_max_size` > 20 MB; `max_execution_time` suficiente para publicar con imágenes (F0-1).

## Fase 2

**Roles y aislamiento (F2-1/F2-2).** Dos roles: `superadmin` (todo: blogs, conexión SFTP, usuarios, regenerar, comprobar) y `blog_user` (solo escribe y publica en los blogs que tiene asignados, sin ver datos de conexión). El aislamiento tiene tres capas: rutas (`superadmin`), políticas (`BlogPolicy`, `ArticlePolicy`) y un filtro global (`BlogAccessScope`) sobre blogs, artículos, medios, despliegues y actividad, de modo que un olvido en un controlador no filtra datos de otro cliente. Usuarios en `/users`. El panel muestra siempre el blog y su dominio, un selector para cambiar de blog, los errores de publicación pendientes y pide confirmación nombrando el dominio antes de publicar o eliminar.

**Doble factor por correo (F2-3).** `PUBLIBLOG_2FA=true` (apagado por defecto: el plan lo sitúa tras el primer cliente real). Tras la contraseña se envía un código de 6 dígitos que caduca (10 min), vale una vez, se invalida tras 5 fallos y solo se guarda su huella HMAC. Reenvío limitado a 3 por hora. Requiere `MAIL_*` configurado.

**Cola con cron y regeneración masiva (F2-4).** Una sola línea de cron en el servidor del CMS:

```
* * * * * cd /ruta/publiblog/publiblog && php artisan schedule:run >> /dev/null 2>&1
```

procesa la cola (`queue:work --stop-when-empty`), marca como error las operaciones atascadas (`deployments:reap`) y limpia imágenes (`blog:clean-media`). Por defecto las publicaciones siguen siendo síncronas (el usuario ve el resultado al momento); con `PUBLIBLOG_QUEUE_PUBLISH=true` se encolan y la pantalla del artículo se refresca sola. **Regenerar blog** (panel o `blog:regenerate`) vuelve a generar los artículos publicados con la plantilla actual por lotes (`PUBLIBLOG_REGENERATE_BATCH`, 25), con barra de progreso, y sube solo lo que cambió (hash). Regenera lo *publicado* (`*.published.json`), nunca un borrador con cambios sin publicar. El panel avisa cuando la plantilla cambió desde la última regeneración. Un fallo en un artículo no detiene al resto; un fallo de conexión sí.

**Importar de WordPress (F2-5).** `blog:import-wordpress {blog} export.xml`: conserva fecha (GMT → zona del blog), slug si es válido y está libre, título, extracto, autor, SEO de Yoast, imágenes (de `--images-dir` o descargadas, con protección contra SSRF) e imagen destacada; reescribe enlaces internos entre entradas importadas; limpia Gutenberg y shortcodes. Es idempotente (`source_ref` = `wp:ID`), lee el XML en flujo y escribe un informe CSV con una fila por entrada (estado, URL antigua → nueva, avisos). Los artículos nacen como borradores; `--publish` publica en lote los que estaban publicados (listado y sitemap una sola vez).

**Actividad, imágenes huérfanas y verificación (F2-6).** `/activity` registra quién cambió qué (qué campos, no el contenido ni credenciales), por blog, persona y tipo; cada artículo muestra su propia actividad. `blog:clean-media` borra imágenes que ningún JSON (borrador ni publicado) menciona tras 14 días de gracia, también en el servidor del cliente. `blog:verify` / «Comprobar el servidor» lee por SFTP cada HTML publicado y lo compara con `published_hash` (detecta archivos editados a mano, borrados, imágenes o reglas `.htaccess` perdidas) y permite «Corregir diferencias» subiendo solo esos artículos. `PUBLIBLOG_VERIFY_WEEKLY=true` lo programa cada lunes.

**Documentación (F2-7).** `docs/alta-de-cliente.md` (proceso de alta, comprobaciones y migración), `docs/plantilla-blog.md` (contrato de la plantilla) y `docs/prompt-plantilla-ia.md` (prompt para generar la plantilla con IA).

En local, con `QUEUE_CONNECTION=database` las acciones en cola (regenerar, comprobar, importar con `--queue`) esperan a un trabajador: lanza `php artisan queue:work` en otra terminal, o pon `QUEUE_CONNECTION=sync` en el `.env` para que se ejecuten al instante.

### Variables de entorno nuevas

| Variable | Defecto | Efecto |
|---|---|---|
| `PUBLIBLOG_2FA` | `false` | doble factor por correo |
| `PUBLIBLOG_2FA_TTL` / `_ATTEMPTS` | `10` / `5` | caducidad (min) e intentos del código |
| `PUBLIBLOG_QUEUE_PUBLISH` | `false` | publicar mediante la cola |
| `PUBLIBLOG_REGENERATE_BATCH` | `25` | artículos por lote en regenerar / verificar |
| `PUBLIBLOG_VERIFY_WEEKLY` | `false` | comprobar los servidores cada lunes |
| `PUBLIBLOG_SITEMAP_LIMIT` | `500` | URLs por archivo de sitemap |

## Fase 3: categorías, redirecciones y cabecera/pie compartidos

**Categorías.** Cada blog tiene sus categorías (`/blog/{categoria}/`, con listado y paginado propios: `{categoria}/index.html`, `{categoria}/pagina/N/index.html`). Un artículo puede estar en varias; la **principal** (la primera) forma parte de su URL: `/blog/{principal}/{slug}`. El archivo se guarda **una sola vez** (`{principal}/{slug}.html`): las demás categorías lo listan enlazando a esa URL, no hay copias. Un blog nuevo no trae ninguna categoría ni se crea una «General» por omisión: un artículo nuevo nace sin categoría y quien edita elige al menos una para poder publicar (la primera que marca es la principal; publicar sin categoría se bloquea con un aviso). Opcionalmente un blog puede tener una «categoría de reserva», que solo usa el importador para las entradas sin categoría; el slug de una categoría no cambia una vez creada (el nombre sí). Gestión en *Blog → Categorías*. El importador de WordPress crea las categorías y elige como principal la de Yoast o, si no, la de menor ID (como arma WordPress su permalink).

**`.htaccess` de tamaño fijo.** Apache relee el `.htaccess` en cada petición, así que no hay una regla por artículo: el bloque tiene tres reglas genéricas (lo que existe se sirve tal cual, se quita la barra final con un 301, `/categoria/slug` sirve `/categoria/slug.html`) y, solo para los artículos que cambiaron de URL, una redirección 301 por URL antigua. Publicar un artículo ya no reescribe el `.htaccess`.

**Cambiar la categoría principal** mueve el archivo (sube el nuevo, retira el viejo y las carpetas que queden vacías) y deja una **redirección 301 automática** de la URL anterior. Lo mismo ocurre con los artículos del formato anterior (`000123-slug.html` en la raíz), que siguen funcionando con su regla hasta que se republican. Las URL antiguas de una importación de WordPress que estén en el mismo dominio y dentro de `/blog/` también redirigen; las de fuera de `/blog/` hay que redirigirlas en la web del cliente (el CSV de la importación es la tabla de equivalencias).

**Cabecera y pie compartidos (SSI), opcional por blog.** La plantilla pone la cabecera y el pie con `@siteHeader` / `@siteFooter` (parciales `partials/header` y `partials/footer`, iguales en todas las páginas). Con SSI activado cada página lleva `<!--#include virtual="/blog/_inc/header.html" -->` y cambiar la cabecera o el pie solo sube dos archivos en vez de regenerar todos los artículos. Se activa desde el panel del blog (*Comprobar el servidor y activar*): antes de tocar el `.htaccess` real se prueba en una carpeta aparte (`_ssi_probe/`) que el servidor ejecuta las inclusiones; si no (falta `mod_include` o `AllowOverride Options`, que daría un 500 en todo `/blog/`) no se activa y no se modifica nada. Una vez activo, cada publicación comprueba por HTTP que las inclusiones se ejecutan. Desactivarlo regenera el blog con la cabecera copiada en cada página.

## Pendiente (fases siguientes)

Mejoras posteriores del plan: Markdown junto al HTML, datos estructurados y Open Graph avanzado, vídeo incrustado, redirecciones 410 para contenido eliminado, programación y aprobación editorial, solicitudes de cambio sobre el resto de la web. F1b-4 (segunda medida de imagen para el listado) sigue pendiente. Las tareas de servidor (F0) y las pruebas contra el hosting real son del equipo de infraestructura.
