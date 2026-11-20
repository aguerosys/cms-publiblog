<?php

/*
 * Servidor FTPS falso (solo el principio: saludo, AUTH TLS y saludo TLS), para probar la lectura del certificado.
 * Lo lanzan las pruebas como proceso aparte.
 *
 *   php fake_ftps_server.php <archivo-donde-escribe-el-puerto> <server.pem> <modo> <conexiones>
 *
 * Modos: ok (ofrece TLS) · no-tls (contesta 502 a AUTH TLS) · ssh (no es un servidor FTP)
 */

[, $portFile, $pem, $mode, $count] = $argv;

$context = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false, 'allow_self_signed' => true]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if (! $server) {
    fwrite(STDERR, "No se pudo abrir el servidor: {$error}\n");
    exit(1);
}

$port = explode(':', (string) stream_socket_get_name($server, false))[1];
file_put_contents($portFile.'.tmp', $port);
rename($portFile.'.tmp', $portFile);

for ($i = 0; $i < (int) $count; $i++) {
    $connection = @stream_socket_accept($server, 20);

    if (! $connection) {
        break;
    }

    if ($mode === 'ssh') {
        fwrite($connection, "SSH-2.0-Falso\r\n");
        fclose($connection);

        continue;
    }

    fwrite($connection, "220-Servidor de pruebas\r\n220 Listo\r\n");

    if (! str_starts_with(trim((string) fgets($connection)), 'AUTH TLS')) {
        fclose($connection);

        continue;
    }

    if ($mode === 'no-tls') {
        fwrite($connection, "502 AUTH no soportado\r\n");
        fclose($connection);

        continue;
    }

    fwrite($connection, "234 Honored\r\n");
    @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    @fgets($connection);
    @fclose($connection);
}
