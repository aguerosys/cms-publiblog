# demoblog (web cliente de prueba)

Web **estática** que simula el servidor de un cliente. Es el destino de publicación del CMS (`../publiblog`).

- `index.html`, `css/` — el sitio del cliente. El CMS **no** los toca.
- `blog/` — lo único que el CMS escribe (artículos `000123-slug.html`, `img/`, `.htaccess`). Su contenido está en `.gitignore`.
- `router.php` — servidor de desarrollo que emula las reglas `.htaccess` (el servidor integrado de PHP no las lee).

```
php -S localhost:8081 -t demoblog demoblog/router.php
```

En producción (`demoblog.publiup.com`) esto es Apache con `AllowOverride FileInfo`, `FollowSymLinks` y un usuario SFTP confinado a `/blog/` (tarea F0-2).
