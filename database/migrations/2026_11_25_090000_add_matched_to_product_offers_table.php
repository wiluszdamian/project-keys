<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            // Oferta znaleziona w innym sklepie: true = AI uznało za ten sam produkt, false = inny wariant lub zestaw
            // (widoczna w panelu, nie śledzona, chyba że osoba włączy). null = link dodany ręcznie.
            $table->boolean('matched')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('product_offers', fn (Blueprint $table) => $table->dropColumn('matched'));
    }
};
