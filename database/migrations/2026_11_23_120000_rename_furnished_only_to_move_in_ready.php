<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Właściciel: liczy się, czy da się od razu zamieszkać (podłogi, kuchnia, łazienka), meble nie są konieczne.
    public function up(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->renameColumn('furnished_only', 'move_in_ready');
        });
    }

    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->renameColumn('move_in_ready', 'furnished_only');
        });
    }
};
