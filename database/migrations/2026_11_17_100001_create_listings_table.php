<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('portal');
            $table->string('external_id');
            $table->text('url');
            $table->string('title');
            $table->enum('deal_type', ['rent', 'sale']);
            $table->unsignedInteger('price')->nullable();
            $table->unsignedInteger('rent_extra')->nullable();
            $table->decimal('area', 7, 2)->nullable();
            $table->unsignedInteger('price_per_m2')->nullable();
            $table->unsignedSmallInteger('rooms')->nullable();
            $table->string('floor')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('street')->nullable();
            $table->boolean('is_private_owner')->nullable();
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->jsonb('raw');
            $table->string('content_hash');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['portal', 'external_id']);
            $table->index(['city', 'district']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
