<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2-4: regeneración masiva con progreso (deployments de acción "regenerate" sin artículo) y
 * huella de la plantilla con la que se generó por última vez el blog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->unsignedInteger('total_items')->nullable()->after('files_count');
            $table->unsignedInteger('done_items')->default(0)->after('total_items');
            $table->unsignedInteger('failed_items')->default(0)->after('done_items');
            $table->boolean('force')->default(false)->after('failed_items');
        });

        Schema::table('blogs', function (Blueprint $table) {
            $table->char('template_hash', 40)->nullable()->after('template_version');
        });
    }

    public function down(): void
    {
        Schema::table('blogs', fn (Blueprint $t) => $t->dropColumn('template_hash'));
        Schema::table('deployments', fn (Blueprint $t) => $t->dropColumn(['total_items', 'done_items', 'failed_items', 'force']));
    }
};
