<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (F2-1): un usuario "blog_user" solo ve y modifica los blogs que tiene asignados.
 * El superadmin (role en users) los ve todos y es el único que gestiona blogs y usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('editor');
            $table->timestamps();

            $table->unique(['blog_id', 'user_id']);
        });

        // Un usuario nuevo sin rol explícito no debe nacer con todos los permisos.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('blog_user')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('superadmin')->change();
        });

        Schema::dropIfExists('blog_user');
    }
};
