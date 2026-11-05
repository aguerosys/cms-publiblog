<?php

/*
 * Servidor de desarrollo para la web de prueba (solo local):
 *
 *     php -S localhost:8081 -t demoblog demoblog/router.php
 *
 * El servidor integrado de PHP no lee .htaccess, así que este router emula lo único que
 * Publiblog necesita: las reglas "RewriteRule ^slug/?$ 000123-slug.html [END]" del bloque
 * gestionado por el CMS en /blog/.htaccess. En producción lo hace Apache.
 */

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = __DIR__;

// Nunca servir archivos ocultos (.htaccess, .htaccess.bak, temporales).
if (preg_match('#/\.[^/]*$#', $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$file = $root.$path;

if (is_dir($file)) {
    $file = rtrim($file, '/').'/index.html';
}

if (is_file($file)) {
    return false; // lo sirve el servidor integrado
}

if (preg_match('#^/blog/([^/]+)/?$#', $path, $m) && is_file($root.'/blog/.htaccess')) {
    foreach (file($root.'/blog/.htaccess', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('#^RewriteRule \^([a-z0-9-]+)/\?\$ (\S+\.html) \[END\]$#', trim($line), $r) && $r[1] === $m[1]) {
            $target = $root.'/blog/'.$r[2];

            if (is_file($target)) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($target);

                return true;
            }
        }
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';

return true;
