<?php

use App\Scrapers\OtodomScraper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Otodom: klucz oferty to kod z adresu (ID4E1rD), nie liczbowe id.
 * Oferty z tymczasowym id (9000…) mieszały kilka ogłoszeń. Zostają ze starym kluczem
 * i przestają się aktualizować, a prawdziwe ogłoszenia wejdą jako nowe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $taken = [];

        $listings = DB::table('listings')->where('portal', 'otodom')
            ->where('external_id', 'not like', '9000%')
            ->orderBy('id')
            ->get(['id', 'url', 'external_id']);

        foreach ($listings as $listing) {
            $code = OtodomScraper::codeFromSlug($listing->url);

            if ($code === null || isset($taken[$code])) {
                continue;
            }

            $taken[$code] = true;
            DB::table('listings')->where('id', $listing->id)->update(['external_id' => $code]);
        }
    }

    public function down(): void
    {
        DB::table('listings')->where('portal', 'otodom')->where('external_id', 'like', 'ID%')
            ->update(['external_id' => DB::raw("raw->>'id'")]);
    }
};
