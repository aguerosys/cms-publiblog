<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2-5: un artículo importado recuerda de dónde viene («wp:1234») para que importar dos veces el
 * mismo archivo no duplique nada, y si debe publicarse cuando se lance la publicación masiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('source_ref', 64)->nullable()->after('public_id');
            $table->boolean('import_publish')->default(false)->after('source_ref');

            $table->unique(['blog_id', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropUnique(['blog_id', 'source_ref']);
            $table->dropColumn(['source_ref', 'import_publish']);
        });
    }
};
