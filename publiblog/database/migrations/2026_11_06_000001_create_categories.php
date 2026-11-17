<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías (F3): una por URL (la principal) y las demás solo para listados.
 *
 * - `categories`: por blog; el slug es la carpeta pública (/blog/{slug}/) y no cambia una vez creada.
 * - `article_category`: refleja el estado PUBLICADO del artículo (no el borrador): el listado y la
 *   regeneración trabajan con esto. Las categorías del borrador viven en el JSON.
 * - `articles.redirects`: URL antiguas del artículo (relativas a /blog/) que redirigen con 301.
 * - `articles.live_summary`: lo que el listado necesita de lo publicado, para no abrir miles de JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['blog_id', 'slug']);
        });

        Schema::create('article_category', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->primary(['article_id', 'category_id']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->json('redirects')->nullable()->after('remote_path');
            $table->json('live_summary')->nullable()->after('redirects');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['redirects', 'live_summary']);
        });

        Schema::dropIfExists('article_category');
        Schema::dropIfExists('categories');
    }
};
