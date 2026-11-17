<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pages_stale_at`: desde cuándo hay cambios (p. ej. una categoría renombrada) que las páginas de artículos
 * ya publicadas todavía no llevan. El panel lo avisa hasta que una regeneración completa lo limpia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->timestamp('pages_stale_at')->nullable()->after('template_hash');
        });
    }

    public function down(): void
    {
        Schema::table('blogs', fn (Blueprint $t) => $t->dropColumn('pages_stale_at'));
    }
};
