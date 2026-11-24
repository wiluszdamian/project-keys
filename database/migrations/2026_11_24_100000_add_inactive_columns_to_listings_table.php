<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Ogłoszenie zdjęte z portalu: od kiedy i skąd wiemy (410, komunikat "archiwalne", przekierowanie).
            $table->timestamp('inactive_at')->nullable()->index();
            $table->string('inactive_reason')->nullable();
            // Ostatnie sprawdzenie strony ogłoszenia (listings:check-active).
            $table->timestamp('availability_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['inactive_at', 'inactive_reason', 'availability_checked_at']);
        });
    }
};
