<?php

return [

    /*
    | Prefijo público bajo el que viven los artículos en la web del cliente
    | (https://dominio.com/blog/mi-articulo). El CMS solo escribe dentro de él.
    */
    'public_prefix' => 'blog',

    /*
    | Slugs que no se pueden usar para un artículo. Ampliable por configuración.
    */
    'reserved_slugs' => [
        'index', 'sitemap', 'categoria', 'pagina', 'img', 'assets', 'admin',
        ...array_filter(explode(',', (string) env('PUBLIBLOG_RESERVED_SLUGS', ''))),
    ],

    'slug_max_length' => (int) env('PUBLIBLOG_SLUG_MAX', 80),

    /*
    | Imágenes: se recodifican siempre en el servidor (sin EXIF ni cargas ocultas).
    */
    'images' => [
        'max_upload_kb' => (int) env('PUBLIBLOG_IMAGE_MAX_KB', 20480),
        'max_side' => (int) env('PUBLIBLOG_IMAGE_MAX_SIDE', 1600),
        'quality' => (int) env('PUBLIBLOG_IMAGE_QUALITY', 80),
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'publish' => [
        // Segundos máximos esperando el bloqueo del blog (un trabajo a la vez por blog).
        'lock_wait' => (int) env('PUBLIBLOG_LOCK_WAIT', 30),
        'lock_ttl' => (int) env('PUBLIBLOG_LOCK_TTL', 300),
        'sftp_timeout' => (int) env('PUBLIBLOG_SFTP_TIMEOUT', 20),
        'verify_timeout' => (int) env('PUBLIBLOG_VERIFY_TIMEOUT', 15),
        // Segundos de ejecución permitidos por publicación (0 = sin límite).
        'time_limit' => (int) env('PUBLIBLOG_TIME_LIMIT', 300),
    ],

    /*
    | Usuario administrador inicial (php artisan db:seed).
    */
    'admin' => [
        'name' => env('PUBLIBLOG_ADMIN_NAME', 'Administrador'),
        'email' => env('PUBLIBLOG_ADMIN_EMAIL'),
        'password' => env('PUBLIBLOG_ADMIN_PASSWORD'),
    ],

    // Detrás de Cloudflare: "*" o lista de IP/CIDR separada por comas. Vacío = no se confía en ningún proxy.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // URLs máximas por archivo de sitemap. Se mantiene bajo (500) para que cada archivo pese poco;
    // el protocolo permitiría 50.000 URLs / 50 MB.
    'sitemap_limit' => (int) env('PUBLIBLOG_SITEMAP_LIMIT', 500),

    // Versión del contrato JSON de artículo.
    'schema_version' => 1,
];
