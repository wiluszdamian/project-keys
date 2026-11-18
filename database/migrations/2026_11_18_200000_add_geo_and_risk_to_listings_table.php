<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('street');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            // portal (pinezka z portalu), address, street, district, city
            $table->string('geo_precision', 20)->nullable()->after('lng');
            $table->timestamp('geocoded_at')->nullable()->after('geo_precision');
            // dHash głównego zdjęcia (16 znaków hex) do wykrywania tych samych zdjęć w innych ofertach.
            $table->string('image_hash', 16)->nullable()->after('image_url');
            $table->jsonb('risk_flags')->nullable()->after('note');
            $table->index('image_hash');
        });

        Schema::create('commute_places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // Akceptowalny dojazd w minutach (informacja dla AI i filtr w panelu).
            $table->unsignedSmallInteger('max_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('listing_commutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commute_place_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 10); // car, bike
            $table->unsignedSmallInteger('minutes');
            $table->decimal('km', 6, 1);
            $table->timestamps();
            $table->unique(['listing_id', 'commute_place_id', 'mode']);
            $table->index(['commute_place_id', 'mode', 'minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_commutes');
        Schema::dropIfExists('commute_places');
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['image_hash']);
            $table->dropColumn(['lat', 'lng', 'geo_precision', 'geocoded_at', 'image_hash', 'risk_flags']);
        });
    }
};
