<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->char('public_id', 6);
            $table->string('slug', 120)->nullable();
            $table->string('title')->nullable();
            $table->string('status', 16)->default('draft'); // draft | published | error
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->char('content_hash', 64)->nullable();    // hash del HTML generado
            $table->char('published_hash', 64)->nullable();  // hash del HTML subido al cliente
            $table->string('remote_path')->nullable();
            $table->unsignedBigInteger('last_deployment_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['blog_id', 'public_id']);
            $table->unique(['blog_id', 'slug']);
            $table->index(['blog_id', 'status', 'published_at']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('local_path');   // relativo al disco "blogs"
            $table->string('remote_path');  // relativo a /blog/ (img/2026/archivo.jpg)
            $table->string('mime', 64);
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('bytes');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'article_id']);
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 16);  // publish | update | unpublish | index | sitemap | regenerate
            $table->string('status', 16);  // queued | running | ok | error
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedInteger('template_version')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('media');
        Schema::dropIfExists('articles');
    }
};
