<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->unsignedSmallInteger('score');
            $table->text('summary');
            $table->jsonb('pros');
            $table->jsonb('red_flags');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->unique(['listing_id', 'search_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_analyses');
    }
};
