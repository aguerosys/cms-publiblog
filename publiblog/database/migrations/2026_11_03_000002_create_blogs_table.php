<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain');
            $table->string('base_url');                       // https://dominio.com (sin /blog)
            $table->string('remote_dir')->default('/blog/');  // raíz de /blog/ vista por el usuario SFTP
            $table->string('locale', 10)->default('es');
            $table->string('timezone', 64)->default('Europe/Madrid');
            $table->unsignedSmallInteger('posts_per_page')->default(12);
            $table->string('template', 64)->default('demoblog');
            $table->unsignedInteger('template_version')->default(1);

            $table->string('sftp_auth', 16)->default('password'); // password | key | local (solo desarrollo)
            $table->string('sftp_host')->nullable();
            $table->unsignedSmallInteger('sftp_port')->default(22);
            $table->string('sftp_user')->nullable();
            $table->text('sftp_secret')->nullable();      // cifrado (cast encrypted)
            $table->text('sftp_passphrase')->nullable();  // cifrado, solo clave SSH
            $table->string('host_key_fingerprint')->nullable();

            $table->boolean('verify_http')->default(true);
            $table->string('status', 24)->default('pending'); // pending | active | connection_error | hostkey_changed
            $table->unsignedInteger('last_public_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
