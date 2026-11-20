<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transakcje mieszkań z Rejestru Cen Nieruchomości i porównanie oferty sprzedaży z nimi.
     * Bez danych stron transakcji i notariusza: nie są potrzebne.
     */
    public function up(): void
    {
        Schema::create('rcn_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('rcn_id')->unique();
            $table->string('teryt', 8);
            $table->string('transaction_ref')->nullable();
            $table->date('date');
            $table->string('market')->nullable();
            $table->unsignedInteger('price');
            $table->decimal('area', 7, 2);
            $table->unsignedInteger('price_per_m2');
            $table->unsignedSmallInteger('rooms')->nullable();
            $table->smallInteger('floor')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->timestamps();
            $table->index(['lat', 'lng']);
            $table->index('date');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->jsonb('transaction_comparison')->nullable();
            $table->timestamp('transactions_compared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['transaction_comparison', 'transactions_compared_at']);
        });

        Schema::dropIfExists('rcn_transactions');
    }
};
