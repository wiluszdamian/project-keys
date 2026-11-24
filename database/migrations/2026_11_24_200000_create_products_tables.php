<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monitor cen: produkt, który chcemy kupić, i jego oferty w sklepach (link wklejony ręcznie albo znaleziony w sieci).
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('image_url')->nullable();
            $table->string('brand')->nullable();
            // Kod EAN/GTIN i MPN ze strony sklepu, pomagają znaleźć ten sam produkt w innych sklepach.
            $table->string('gtin')->nullable();
            $table->string('mpn')->nullable();
            $table->decimal('target_price', 12, 2)->nullable();
            $table->boolean('notify_drops')->default(true);
            // Obniżka mniejsza niż tyle procent nie daje powiadomienia (0 = każda).
            $table->unsignedSmallInteger('min_drop_percent')->default(0);
            $table->boolean('notify_rises')->default(false);
            $table->boolean('notify_stock')->default(true);
            $table->unsignedSmallInteger('check_every_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            // Najlepsza cena wśród dostępnych ofert i historia: pierwsza, najniższa, najwyższa.
            $table->decimal('best_price', 12, 2)->nullable();
            $table->foreignId('best_offer_id')->nullable();
            $table->decimal('initial_price', 12, 2)->nullable();
            $table->decimal('lowest_price', 12, 2)->nullable();
            $table->timestamp('lowest_price_at')->nullable();
            $table->decimal('highest_price', 12, 2)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('offers_searched_at')->nullable();
            // Ocena AI: werdykt (buy, wait, good), ocena 1-10, podsumowanie, powody.
            $table->jsonb('ai_advice')->nullable();
            $table->timestamp('ai_advice_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            // sha1 adresu bez parametrów śledzących: ten sam link nie wchodzi dwa razy.
            $table->string('url_key', 40);
            $table->string('shop');
            $table->string('title')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            // Cena przed promocją (przekreślona) i najniższa z 30 dni (Omnibus), gdy sklep je podaje.
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('lowest_30d_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->boolean('available')->nullable();
            // manual (wklejony link) albo search (znaleziony w sieci).
            $table->string('source')->default('manual');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            // Skąd cena: json-ld, meta, microdata, shop (znany sklep), text (z treści strony) albo ai.
            $table->string('extracted_by')->nullable();
            // Dlaczego AI uznało ofertę za ten sam produkt.
            $table->string('match_note', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'url_key']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_offer_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('available')->nullable();
            $table->timestamp('checked_at');

            $table->index(['product_offer_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_offers');
        Schema::dropIfExists('products');
    }
};
