<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tygodniowy raport rynku: osobno wynajem i sprzedaż. Liczby w data (jsonb), żeby porównać z poprzednim tygodniem.
     */
    public function up(): void
    {
        Schema::create('market_reports', function (Blueprint $table) {
            $table->id();
            $table->enum('deal_type', ['rent', 'sale']);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->jsonb('data');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['deal_type', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_reports');
    }
};
