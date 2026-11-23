<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Punkty z OpenStreetMap (sklepy, żłobki, przedszkola, szkoły) i wyliczona infrastruktura przy ofercie.
    public function up(): void
    {
        Schema::create('pois', function (Blueprint $table) {
            $table->id();
            $table->string('osm_id', 30);
            $table->string('category', 20);
            $table->string('name')->nullable();
            $table->string('brand')->nullable();
            $table->string('address')->nullable();
            $table->double('lat');
            $table->double('lng');
            $table->timestamps();

            $table->unique(['osm_id', 'category']);
            $table->index(['category', 'lat', 'lng']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->jsonb('infrastructure')->nullable();
            $table->timestamp('infrastructure_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pois');

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['infrastructure', 'infrastructure_at']);
        });
    }
};
