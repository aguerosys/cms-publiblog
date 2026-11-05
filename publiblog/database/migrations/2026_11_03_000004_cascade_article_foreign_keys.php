<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * media y deployments colgaban de blogs (CASCADE) y de articles (SET NULL). Al borrar un blog,
 * MySQL no puede resolver ambos caminos sobre la misma fila (error 1452). Se unifica en CASCADE:
 * son datos derivables (blog:reindex reconstruye media desde los archivos del CMS).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['media', 'deployments'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_article_id_foreign");
            });

            Schema::table($table, function (Blueprint $t) {
                $t->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['media', 'deployments'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_article_id_foreign");
            });

            Schema::table($table, function (Blueprint $t) {
                $t->foreign('article_id')->references('id')->on('articles')->nullOnDelete();
            });
        }
    }
};
