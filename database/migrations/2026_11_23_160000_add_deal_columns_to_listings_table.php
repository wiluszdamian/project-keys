<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Okazja: powody (lista {type, text}), od kiedy jest okazją i kiedy poszło powiadomienie.
            $table->jsonb('deal_reasons')->nullable();
            $table->timestamp('deal_at')->nullable()->index();
            $table->timestamp('deal_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['deal_reasons', 'deal_at', 'deal_notified_at']);
        });
    }
};
