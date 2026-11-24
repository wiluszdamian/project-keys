<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sklepy, w których monitor cen szuka produktu za darmo: po mapie strony (sitemap), którą sklep publikuje dla robotów.
        Schema::create('product_shops', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('name')->nullable();
            // Mapa strony. Puste = bierzemy adresy z robots.txt albo /sitemap.xml.
            $table->string('sitemap_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            // manual (dodany przez osobę), ai (zaproponowany przez AI), default (z listy startowej).
            $table->string('source')->default('manual');
            // ok, empty (mapa bez produktów), blocked (403/429), error.
            $table->string('status')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('urls_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // Adresy stron z mapy sklepu. slug: słowa z adresu małymi literami bez polskich znaków, do szukania po nazwie.
        Schema::create('product_shop_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_shop_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->text('slug');
            $table->index('product_shop_id');
        });

        Schema::table('products', function (Blueprint $table) {
            // Czego szukać w innych sklepach, np. "Cybex Balios S Lux". Puste = AI albo początek nazwy.
            $table->string('search_query')->nullable()->after('mpn');
        });

        // Sprawdzone 2026-11-24: mapa strony dostępna z serwera, adresy produktów mają nazwę produktu.
        DB::table('product_shops')->insert([
            ['domain' => 'bobowozki.com.pl', 'name' => 'Bobowózki', 'sitemap_url' => 'https://www.bobowozki.com.pl/sitemaps/google_sitemap_1_product.xml', 'source' => 'default', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('search_query'));
        Schema::dropIfExists('product_shop_urls');
        Schema::dropIfExists('product_shops');
    }
};
