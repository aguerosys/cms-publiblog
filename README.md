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
php artisan test                         # 60+ pruebas (MySQL, base publiblog_test)
php artisan blog:validate-template demoblog
php artisan blog:reindex {blog_id}       # reconstruye articles y media desde los archivos del CMS
php artisan blog:regenerate {blog_id}    # regenera y vuelve a subir todos los artículos publicados (p. ej. tras restaurar la web del cliente)
```

## Cómo funciona (fase 1)

1. **Alta de blog** (`/blogs`): dominio, URL base, plantilla y datos SFTP. El secreto va cifrado (`encrypted`) y no se puede volver a leer, solo reemplazar. «Probar conexión» muestra la huella del servidor para confirmarla la primera vez; si cambia, se bloquea la publicación hasta que un administrador la confirme.
2. **Artículo**: «Nuevo artículo» abre un borrador con su ID de 6 dígitos. El editor (Tiptap) entrega HTML, que se sanea con HTMLPurifier al guardar y otra vez al generar. Las imágenes se recodifican con GD (sin EXIF), máx. 1.600 px.
3. **Guardado** (`storage/app/blogs/{blog}/articles/{shard}/{id}.json|html`): JSON → HTML → fila de `articles`, con escritura atómica. Es la fuente de verdad.
4. **Publicar** (`PublishArticle`, `dispatchSync` en fase 1): bloqueo por blog → huella → imágenes nuevas → HTML → bloque de `.htaccess` (con copia `.bak`) → verificación de la URL pública (200 + meta `blog-article-id`). Si falla, el contenido queda guardado, el artículo pasa a `error` y «Reintentar» repite todo (los pasos remotos son idempotentes).
5. **Editar** = cargar el JSON y sobrescribir; si la `version` cambió, avisa antes de pisar. **Eliminar** = quitar la regla, borrar el archivo remoto y conservar el JSON.

URLs (opción A del plan): `/blog/mi-articulo` se sirve desde `/blog/000123-mi-articulo.html` con una regla por artículo entre `# BEGIN publiblog` y `# END publiblog`; el resto del `.htaccess` no se toca.

## Listado y sitemap (fase 1b)

Tras cada publicación o eliminación se regeneran `/blog/` (paginado: `index.html`, `pagina/N/index.html`, sin reglas `.htaccess`) y `sitemap.xml` (se parte en `sitemap-N.xml` con un índice al pasar de 500 URLs; configurable con `PUBLIBLOG_SITEMAP_LIMIT`). Solo se suben los archivos cuyo hash cambió (manifiesto en `storage/app/blogs/{blog}/build/`). La plantilla necesita `index.blade.php` además de `article.blade.php`.

## Plantillas

Vistas Blade en `resources/views/blogs/{plantilla}/` (`article.blade.php`, `index.blade.php` + parciales). Solo ven `$article` / `$articles` + `$pagination`, `$seo` y `$blog` (contrato en el plan). `php artisan blog:validate-template {plantilla}` comprueba etiquetas obligatorias, escape, ausencia de PHP suelto y de `{!! !!}` que no sea `body_html`.

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

## Pendiente (fases siguientes)

Fase 2: roles y aislamiento por blog, 2FA por correo, cola con cron, regeneración masiva, importador XML de WordPress.
