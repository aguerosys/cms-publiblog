<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F2-6: una regeneración puede limitarse a los artículos con diferencias respecto al servidor. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('scope', 16)->nullable()->after('force'); // null = todo el blog | problems
        });
    }

    public function down(): void
    {
        Schema::table('deployments', fn (Blueprint $t) => $t->dropColumn('scope'));
    }
};
