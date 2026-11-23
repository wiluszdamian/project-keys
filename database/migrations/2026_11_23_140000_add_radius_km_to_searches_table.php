<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            // Promień wokół miejscowości w km, null = tylko ta miejscowość.
            $table->unsignedSmallInteger('radius_km')->nullable()->after('districts');
        });
    }

    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->dropColumn('radius_km');
        });
    }
};
