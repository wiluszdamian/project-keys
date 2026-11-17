<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_analyses', function (Blueprint $table) {
            // Pełny koszt miesięczny według AI: najem + czynsz + inne opłaty z opisu.
            $table->unsignedInteger('monthly_cost')->nullable()->after('score');
            $table->jsonb('cost_breakdown')->nullable()->after('monthly_cost');
            // Oceny cząstkowe 1-10: cost, location, standard, fit.
            $table->jsonb('sub_scores')->nullable()->after('cost_breakdown');
            // content_hash ogłoszenia w chwili oceny. Gdy się zmieni, oceniamy jeszcze raz.
            $table->string('listing_content_hash')->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('listing_analyses', function (Blueprint $table) {
            $table->dropColumn(['monthly_cost', 'cost_breakdown', 'sub_scores', 'listing_content_hash']);
        });
    }
};
