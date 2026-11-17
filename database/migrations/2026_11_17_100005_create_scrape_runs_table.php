<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->string('portal');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            // running, ok, blocked, error
            $table->string('status')->default('running');
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->text('error_message')->nullable();

            $table->index(['portal', 'started_at']);
            $table->index(['search_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
