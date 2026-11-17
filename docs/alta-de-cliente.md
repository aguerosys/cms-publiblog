# Alta de un cliente nuevo

Guía paso a paso para dejar el blog de un cliente publicando. Reparte el trabajo entre **servidor** (quien administra el hosting del cliente), **plantilla** (desarrollo) y **panel** (administración). Cada bloque termina con una comprobación; no se pasa al siguiente sin ella.

## 0. Datos que hay que tener

- [ ] Dominio y URL base de la web (`https://cliente.com`, sin `/blog`).
- [ ] Estructura de URL que tenía el blog anterior, si se migra (barra final, prefijos, si llevaba la categoría) y cuántos artículos tiene.
- [ ] Categorías del blog (nombre de cada una; el nombre de la carpeta se deriva de él y no se puede cambiar después) (no hay categoría por defecto: la elige quien edita cada artículo; opcionalmente una «de reserva» para importar entradas sin categoría).
- [ ] Las direcciones del blog serán `https://cliente.com/blog/{categoria}/{articulo}`.
- [ ] Acceso al hosting: panel (Plesk/cPanel) y SFTP.
- [ ] ¿Hay Cloudflare delante de la web? (ver §1.5)
- [ ] Nombre de la plantilla (`cliente`) y el HTML de una página interior de la web.

## 1. Servidor del cliente (lo hace quien administra el hosting)

1. **Usuario SFTP propio** para el blog, confinado a la carpeta `/blog/` (chroot). Una web = un usuario. Mejor con **clave SSH** que con contraseña.
   - Si el usuario está confinado a `/blog/`, en el panel el «Directorio remoto» será `/`.
   - Si ve toda la web, será `/blog/`. El CMS nunca escribe fuera de ese directorio.
2. **Carpeta `/blog/` creada** y con permiso de escritura para ese usuario.
3. **Apache permite `.htaccess` en `/blog/`**: `AllowOverride FileInfo` (reglas `RewriteRule`) y `Options +FollowSymLinks` (o `SymLinksIfOwnerMatch`).
4. **`/blog/` no ejecuta PHP** (defensa adicional: si alguien subiera un `.php`, no se ejecuta).
5. **Cloudflare**: el CMS comprueba cada publicación pidiendo la URL pública. Si Cloudflare tiene un desafío para bots, esa petición puede recibir un 403 y el artículo quedará en «error». Soluciones, por orden de preferencia: permitir la IP del CMS en Cloudflare, o desactivar «Verificar la URL pública» en ese blog (se pierde esa comprobación).
6. **Cabecera y pie compartidos (opcional, recomendado):** `mod_include` activo y `AllowOverride Options` (además de `FileInfo`) en `/blog/`. Sin eso el panel no permite activarlo (lo detecta antes de tocar nada) y el blog funciona igual con la cabecera copiada en cada página. Un `Options` que el servidor no permite da un 500 en todo `/blog/`: por eso la comprobación se hace en una carpeta aparte.
7. **Salida SFTP desde el CMS**: el puerto 22 (o el que sea) del servidor del cliente debe ser alcanzable desde el servidor del CMS.
8. Anotar: servidor, puerto, usuario, método de autenticación y, si se puede, la **huella SHA-256 del servidor** (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256`). Se necesita para el paso 3.4.

**Comprobación:** con un cliente SFTP cualquiera (FileZilla, `sftp`), entrar con el usuario nuevo, crear un archivo en la carpeta y borrarlo. Comprobar que no se puede subir a un nivel superior.

## 2. Plantilla (desarrollo)

1. Crear `publiblog/resources/views/blogs/{cliente}/` siguiendo [plantilla-blog.md](plantilla-blog.md). Para webs nuevas se puede partir del [prompt para IA](prompt-plantilla-ia.md).
2. Validar: `php artisan blog:validate-template {cliente}` → sin errores.
3. Subir los estilos del blog (`blog.css` o lo que corresponda) a **la web del cliente**, no al CMS.
4. Desplegar el CMS (la plantilla es código del repositorio) y ejecutar `php artisan view:clear`.

**Comprobación:** el validador termina en verde.

## 3. Alta en el panel (superadmin)

1. **Blogs → Nuevo blog**. Nombre, dominio, URL base, idioma, zona horaria, plantilla y artículos por página.
2. **Conexión SFTP**: servidor, puerto, usuario, autenticación (clave o contraseña) y secreto. Se guarda cifrado y no se vuelve a mostrar. Directorio remoto según §1.1.
3. **Probar conexión**. El CMS conecta, muestra la **huella del servidor** y escribe y borra un archivo de prueba.
4. **Comparar la huella** con la que dio quien administra el hosting y pulsar «Confirmar huella y conectar». Si no coincide, **no confirmar**: algo está mal. Sin huella confirmada el CMS se niega a publicar.
5. El blog debe quedar en **«Conectado»**.

6. **Categorías** (*Blog → Categorías*): crear las del cliente **antes de publicar nada**: un blog nuevo no trae ninguna (no se crea una «General» sola) y no se puede publicar un artículo sin categoría. Los artículos nuevos nacen sin categoría y quien edita elige al menos una; la primera que marca es la principal (la de la URL). Opcional: una «categoría de reserva» (solo la usa la importación de WordPress para las entradas sin categoría). El nombre se puede cambiar; el slug (la carpeta pública) no, una vez creado.
7. **Cabecera y pie compartidos (opcional):** en la ficha del blog, *Comprobar el servidor y activar*. El CMS prueba en la carpeta `_ssi_probe/` que el servidor ejecuta las inclusiones; si lo hace, activa la opción y regenera el blog (los artículos pasan a incluir `/blog/_inc/header.html` y `footer.html`). Si falla, el mensaje dice por qué (falta `mod_include`, `AllowOverride Options`, protección contra bots…) y no se modifica nada. Requiere que la plantilla use `@siteHeader` / `@siteFooter`.

**Comprobación:** estado «Conectado» y huella guardada en la ficha del blog; las categorías creadas; si se activó SSI, «Activado» con la fecha de la comprobación.

## 4. Usuarios del cliente

1. **Usuarios → Nuevo usuario**. Rol «Usuario de blog», contraseña de al menos 12 caracteres y marcar **solo** el blog de ese cliente.
2. Un usuario de blog ve únicamente sus blogs: no ve la conexión SFTP, ni los blogs de otros clientes, ni la lista de usuarios.
3. Si el CMS tiene activado el doble factor (`PUBLIBLOG_2FA=true`), cada usuario recibirá un código por correo en cada acceso; comprobar antes que el correo del servidor funciona.

**Comprobación:** entrar con ese usuario en una ventana privada y confirmar que solo aparece su blog.

## 5. Primera publicación de prueba

1. En el blog, **Nuevo artículo** con título largo, una imagen, una lista y una tabla, en dos categorías (marcando la principal). **Publicar**.
2. Abrir la URL pública (`https://cliente.com/blog/{categoria}/titulo`) en escritorio y en móvil. Comprobar que `https://cliente.com/blog/{categoria}/titulo/` (con barra) redirige a la anterior.
3. Abrir `https://cliente.com/blog/` (listado), el listado de cada categoría (`https://cliente.com/blog/{categoria}/`) y `https://cliente.com/blog/sitemap.xml`.
4. Comprobar que cabecera y pie son los de la web real.
5. **Comprobar el servidor** (en la ficha del blog): debe decir «todo coincide».
6. Cambiar la categoría principal del artículo de prueba y publicar: la URL nueva responde 200, la anterior redirige (301) a ella y el listado de la categoría antigua se actualiza.
7. Eliminar el artículo de prueba (la URL pasa a 404, el listado se actualiza y una categoría que se queda sin artículos desaparece del servidor, sin carpeta vacía).

**Comprobación:** las URL del artículo, el listado y el sitemap responden 200, el diseño es idéntico al de la web y «Comprobar el servidor» está en verde.

## 6. Migrar un blog existente (WordPress)

1. En WordPress: **Herramientas → Exportar → Entradas**. Descargar el XML.
2. Si se puede, bajar también `wp-content/uploads` (más rápido y fiable que descargar las imágenes una a una).
3. Simular primero:
   ```bash
   php artisan blog:import-wordpress {id_blog} exportacion.xml --dry-run
   ```
4. Importar como borradores (con imágenes):
   ```bash
   php artisan blog:import-wordpress {id_blog} exportacion.xml --images-dir=/ruta/wp-content/uploads
   ```
5. Las categorías de WordPress se crean en el blog; la **principal** de cada entrada es la que marca Yoast o, si no, la de menor ID (como arma WordPress su permalink). Las entradas «Sin categoría» van a la categoría de reserva del blog si la tiene; si no, el importador crea «General» (y la marca como de reserva) solo si hay entradas así, y lo anota en el informe. Revisar el **informe CSV** (la ruta se imprime al terminar): hay una fila por entrada con su estado, la URL antigua, la nueva y los avisos (imágenes que no se pudieron traer, shortcodes o vídeos incrustados eliminados, slugs que cambiaron, enlaces internos que quedaron apuntando al sitio antiguo).
6. Revisar a mano una muestra de artículos en el panel. Corregir lo que haga falta.
7. Publicar en lote los que estaban publicados en WordPress:
   ```bash
   php artisan blog:import-wordpress {id_blog} exportacion.xml --publish
   ```
   Es idempotente: las entradas ya importadas se omiten. Con muchas entradas, añadir `--queue` para que lo procese el cron.
8. **Redirecciones**: al publicar, las URL antiguas que estén en **el mismo dominio y dentro de `/blog/`** (por ejemplo `/blog/2021/05/titulo/`) redirigen solas con un 301 a la nueva. Las que estén **fuera de `/blog/`** (por ejemplo `/titulo/` en la raíz, o `/categoria/titulo/`) no las puede gestionar este `.htaccess`: el CSV de la importación es la tabla de equivalencias para crearlas en la web del cliente.

**Comprobación:** un blog importado se ve igual que el original; «Comprobar el servidor» en verde; Search Console sin errores tras enviar el sitemap.

## 7. Operación continua

| Quiero… | Dónde |
|---|---|
| Cambié la cabecera, el pie o el diseño | Desplegar la plantilla → `php artisan view:clear` → ficha del blog → **Regenerar blog**. Con SSI activado, si solo cambió la cabecera o el pie se suben dos archivos en vez de todos los artículos |
| Quiero cambiar la categoría de un artículo | Abrirlo → cambiar la categoría **principal** → Publicar. La URL cambia; la anterior redirige (301) sola. Cambiar solo las categorías secundarias no cambia la URL |
| Quiero renombrar una categoría o cambiar su descripción | *Blog → Categorías → Editar*. El menú y el listado de la categoría se actualizan al guardar. Si cambió el **nombre**, los artículos ya publicados lo llevan en sus migas de pan y enlaces: el panel muestra un aviso en la ficha del blog hasta que se pulsa **Regenerar blog**. El slug y las URL no cambian |
| Una categoría ya no se usa | *Blog → Categorías*. No se puede borrar si tiene artículos publicados |
| Activar o quitar la cabecera y pie compartidos | Ficha del blog → *Cabecera y pie compartidos (SSI)* |
| La web del cliente se restauró de una copia antigua | **Regenerar blog** marcando «Volver a subir todo», o `php artisan blog:regenerate {id}` |
| Sospecho que alguien tocó archivos a mano | Ficha del blog → **Comprobar el servidor** → **Corregir diferencias** |
| ¿Quién cambió qué? | Menú **Actividad** (por blog, persona y tipo) y la sección «Actividad del artículo» |
| Un artículo quedó en «error» | Abrirlo → **Reintentar**. El contenido siempre se conserva |
| Cambió la huella del servidor | El CMS bloquea las publicaciones. Confirmar con quien administra el hosting si fue un cambio legítimo y entonces «Probar conexión» → confirmar |
| Limpiar imágenes que nadie usa | Automático cada noche (14 días de gracia); a mano: `php artisan blog:clean-media --dry-run` |

## Blogs publicados antes de las categorías

Nada se rompe al actualizar el CMS: los artículos antiguos (`000123-slug.html` en la raíz de `/blog/`, con su regla propia en el `.htaccess`) siguen sirviéndose igual y aparecen en el listado. El `.htaccess` pasa al formato nuevo (reglas genéricas + las de esos artículos antiguos) la primera vez que se publica o se regenera algo.

- Un artículo antiguo se traslada a `/blog/{categoria}/{slug}` cuando se **vuelve a publicar** (hay que elegirle categoría: sin ella no se puede publicar de nuevo; hasta entonces el artículo antiguo sigue sirviéndose igual). Su dirección anterior redirige sola con un 301 a la nueva.
- Una categoría nueva no puede llamarse igual que la dirección de un artículo antiguo (`/blog/cocina`): el panel lo avisa hasta que ese artículo se haya republicado con categoría.
- «Comprobar el servidor» marcará «Falta su regla en el .htaccess» hasta que el bloque nuevo se escriba: basta con pulsar *Regenerar blog* (o «Corregir diferencias»).

## Requisitos del CMS (cron y correo)

- **Una línea de cron** en el servidor del CMS: `* * * * * cd /ruta/a/publiblog/publiblog && php artisan schedule:run >> /dev/null 2>&1`. Procesa la cola (regeneraciones, importaciones, comprobaciones, y las publicaciones si `PUBLIBLOG_QUEUE_PUBLISH=true`), limpia operaciones atascadas y borra imágenes huérfanas.
- Sin ese cron, las operaciones en cola se quedan «en cola» para siempre. Se puede procesar a mano con `php artisan queue:work --stop-when-empty`.
- Para el doble factor hace falta `MAIL_*` configurado. Probar el envío antes de activar `PUBLIBLOG_2FA=true`.
- Copias de seguridad: base de datos, `storage/app` y el `.env` **con la `APP_KEY`** (sin ella no se pueden descifrar las credenciales SFTP).

## Lista final de aceptación

- [ ] Usuario SFTP confinado y probado; `/blog/` sin PHP.
- [ ] Blog «Conectado», huella confirmada y comparada con el administrador del hosting.
- [ ] Plantilla validada; artículo de prueba idéntico en diseño al resto de la web (escritorio y móvil).
- [ ] `/blog/`, `/blog/sitemap.xml`, el listado de una categoría y un artículo responden 200; una URL con barra final redirige a la de sin barra.
- [ ] Categorías creadas y artículo de prueba con categoría principal y secundaria.
- [ ] «Comprobar el servidor» en verde.
- [ ] Usuarios del cliente creados, solo con su blog.
- [ ] `robots.txt` de la web del cliente apunta a `https://cliente.com/blog/sitemap.xml` y el sitemap está enviado a Search Console. Con SSI, además `Disallow: /blog/_inc/`.
- [ ] Si se activó SSI: «Comprobar el servidor» en verde y una página del blog muestra cabecera y pie completos.
- [ ] Cron del CMS funcionando (`php artisan schedule:list` lo muestra).
