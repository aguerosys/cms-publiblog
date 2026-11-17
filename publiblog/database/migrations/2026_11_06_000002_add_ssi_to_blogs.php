<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera y pie compartidos (SSI): con `ssi_enabled` los artículos llevan una directiva
 * <!--#include virtual="/blog/_inc/header.html" --> en vez de la cabecera copiada, de modo que cambiar
 * la cabecera o el pie solo obliga a subir dos archivos. Solo se activa tras comprobar en el servidor
 * del cliente que las inclusiones se ejecutan (`ssi_verified_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->boolean('ssi_enabled')->default(false);
            $table->timestamp('ssi_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn(['ssi_enabled', 'ssi_verified_at']);
        });
    }
};
