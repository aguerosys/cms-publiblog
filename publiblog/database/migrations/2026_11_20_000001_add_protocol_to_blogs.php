<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protocolo de la conexión con el servidor del cliente: `sftp` (por defecto) o `ftps` (FTP con TLS explícito, para
 * hostings que no ofrecen SSH). Los campos sftp_* se reutilizan para los dos (servidor, puerto, usuario, secreto).
 *
 * `ftps_trust`: cómo se comprueba el certificado de un servidor FTPS: `pin` (la huella confirmada, como en SFTP) o
 * `ca` (certificado válido de una autoridad: sobrevive a las renovaciones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->string('protocol', 8)->default('sftp')->after('remote_dir');
            $table->string('ftps_trust', 8)->nullable()->after('host_key_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('blogs', fn (Blueprint $t) => $t->dropColumn(['protocol', 'ftps_trust']));
    }
};
