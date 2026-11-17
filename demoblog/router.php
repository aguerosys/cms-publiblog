<?php

/*
 * Servidor de desarrollo para la web de prueba (solo local):
 *
 *     php -S localhost:8081 -t demoblog demoblog/router.php
 *
 * El servidor integrado de PHP no lee .htaccess, así que este router emula lo que Publiblog necesita del
 * bloque que gestiona en /blog/.htaccess (en producción lo hace Apache):
 *
 *   /categoria/slug         sirve /categoria/slug.html
 *   /categoria/slug/        redirige (301) a /categoria/slug
 *   RewriteRule ^slug/?$ 000123-slug.html [END]            artículos anteriores a las categorías
 *   RewriteRule ^vieja/url/?$ https://…/blog/nueva [R=301,L]   URL antiguas
 *   AddOutputFilter INCLUDES .html                         cabecera y pie compartidos (<!--#include virtual="/blog/_inc/…" -->)
 */

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = __DIR__;

function notFound(): bool
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';

    return true;
}

/** Sirve un .html del blog; si el .htaccess activa las inclusiones SSI, las resuelve como haría Apache. */
function serveHtml(string $root, string $file): bool
{
    $html = (string) file_get_contents($file);
    $ht = @file_get_contents($root.'/blog/.htaccess') ?: '';

    if (str_contains($ht, 'AddOutputFilter INCLUDES .html')) {
        $html = preg_replace_callback('#<!--\#include virtual="/blog/([a-z0-9_/.-]+)" -->#', function ($m) use ($root) {
            $part = realpath($root.'/blog/'.$m[1]);

            return $part && str_starts_with($part, realpath($root.'/blog').DIRECTORY_SEPARATOR) ? (string) file_get_contents($part) : '';
        }, $html);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;

    return true;
}

// Nunca servir archivos ocultos (.htaccess, .htaccess.bak, temporales).
if (preg_match('#/\.[^/]*$#', $path)) {
    http_response_code(404);
    echo 'Not found';

    return true;
}

$file = $root.$path;

if (is_dir($file)) {
    if (! str_ends_with($path, '/')) {
        header('Location: '.$path.'/', true, 301); // como mod_dir

        return true;
    }

    $file = rtrim($file, '/').'/index.html';
}

if (is_file($file)) {
    // Las páginas del blog pasan por el filtro de inclusiones; lo demás lo sirve el servidor integrado.
    if (str_starts_with($path, '/blog/') && str_ends_with($file, '.html')) {
        return serveHtml($root, $file);
    }

    return false;
}

if (str_starts_with($path, '/blog/') && is_file($root.'/blog/.htaccess')) {
    $rel = substr($path, strlen('/blog/'));

    // Las URL de artículo no llevan barra final.
    if ($rel !== '' && str_ends_with($rel, '/')) {
        header('Location: /blog/'.rtrim($rel, '/'), true, 301);

        return true;
    }

    // /categoria/slug → /categoria/slug.html
    if ($rel !== '' && preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#', $rel) && is_file($root.'/blog/'.$rel.'.html')) {
        return serveHtml($root, $root.'/blog/'.$rel.'.html');
    }

    foreach (file($root.'/blog/.htaccess', FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);

        // Artículo anterior a las categorías.
        if (preg_match('#^RewriteRule \^([a-z0-9/-]+)/\?\$ (\S+\.html) \[END\]$#', $line, $r) && $r[1] === $rel && is_file($root.'/blog/'.$r[2])) {
            return serveHtml($root, $root.'/blog/'.$r[2]);
        }

        // URL antigua con redirección permanente.
        if (preg_match('#^RewriteRule \^([a-z0-9/-]+)/\?\$ (\S+) \[R=301,L\]$#', $line, $r) && $r[1] === $rel) {
            header('Location: '.$r[2].(isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?'.$_SERVER['QUERY_STRING'] : ''), true, 301);

            return true;
        }
    }
}

return notFound();
