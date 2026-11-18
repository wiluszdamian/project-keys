<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->boolean('notify_enabled')->default(true)->after('notify_channels');
            $table->string('notify_mode')->default('instant')->after('notify_enabled');
            $table->unsignedSmallInteger('notify_every_hours')->nullable()->after('notify_mode');
            $table->string('notify_daily_at', 5)->nullable()->after('notify_every_hours');
            $table->timestamp('notify_last_sent_at')->nullable()->after('notify_daily_at');
        });
    }

    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->dropColumn(['notify_enabled', 'notify_mode', 'notify_every_hours', 'notify_daily_at', 'notify_last_sent_at']);
        });
    }
};
