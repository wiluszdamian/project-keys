<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Ta sama oferta z innego portalu, zapisana wcześniej. Nie powiadamiamy o niej drugi raz.
            $table->foreignId('duplicate_of_id')->nullable()->after('content_hash')->constrained('listings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_id');
        });
    }
};
