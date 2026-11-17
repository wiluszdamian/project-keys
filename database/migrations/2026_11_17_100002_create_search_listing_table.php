<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_listing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->timestamp('matched_at');
            $table->timestamp('notified_at')->nullable();
            $table->string('notify_skipped_reason')->nullable();

            $table->unique(['search_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_listing');
    }
};
