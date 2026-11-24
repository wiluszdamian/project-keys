<?php

use App\Services\TimeOnMarket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Data dodania ogłoszenia według portalu (gdy portal ją podaje). Do liczenia, jak długo oferta wisi.
            $table->timestamp('published_at')->nullable();
            // Oglądanie: termin, pytania (z reguł i od AI) i znacznik wysłanego przypomnienia.
            $table->timestamp('viewing_at')->nullable()->index();
            $table->jsonb('viewing_questions')->nullable();
            $table->timestamp('viewing_questions_at')->nullable();
            $table->timestamp('viewing_reminded_at')->nullable();
        });

        // Daty z danych, które już mamy (raw z portalu).
        DB::table('listings')->select(['id', 'portal', 'raw'])->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $published = TimeOnMarket::publishedAt($row->portal, json_decode((string) $row->raw, true) ?: []);

                if ($published !== null) {
                    DB::table('listings')->where('id', $row->id)->update(['published_at' => $published]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['published_at', 'viewing_at', 'viewing_questions', 'viewing_questions_at', 'viewing_reminded_at']);
        });
    }
};
