<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2-6: registro de actividad (quién cambió qué) y estado de la última comprobación del servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('article_id')->nullable(); // sin FK: el registro sobrevive al artículo
            $table->string('event', 40);
            $table->string('subject', 200)->nullable();           // título o correo en el momento del cambio
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['blog_id', 'created_at']);
            $table->index(['article_id', 'created_at']);
            $table->index('event');
        });

        Schema::table('articles', function (Blueprint $table) {
            // ok | modified | missing | missing_media | no_rule — lo que encontró la última verificación
            $table->string('remote_state', 16)->nullable()->after('published_hash');
            $table->timestamp('remote_checked_at')->nullable()->after('remote_state');
        });
    }

    public function down(): void
    {
        Schema::table('articles', fn (Blueprint $t) => $t->dropColumn(['remote_state', 'remote_checked_at']));
        Schema::dropIfExists('activity_log');
    }
};
