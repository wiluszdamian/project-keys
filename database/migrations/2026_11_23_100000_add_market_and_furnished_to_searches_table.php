<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            // primary albo secondary, null = oba rynki.
            $table->string('market', 20)->nullable()->after('rooms_max');
            $table->boolean('furnished_only')->default(false)->after('market');
            $table->boolean('exclude_visualizations')->default(false)->after('furnished_only');
        });
    }

    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->dropColumn(['market', 'furnished_only', 'exclude_visualizations']);
        });
    }
};
