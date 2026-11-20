<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Nieruchomości-online zapisywało miasto ofert deweloperów razem z dzielnicą ("Załęże Rzeszów").
     */
    public function up(): void
    {
        DB::update(<<<'SQL'
            update listings
            set city = trim(substr(city, length(district) + 2))
            where portal = 'nieruchomosci_online'
              and district is not null
              and city like district || ' %'
        SQL);
    }

    public function down(): void
    {
        // Bez cofania: poprzednia wartość była błędna.
    }
};
