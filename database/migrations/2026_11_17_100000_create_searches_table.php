<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('deal_type', ['rent', 'sale']);
            $table->enum('property_type', ['flat', 'house'])->default('flat');
            $table->string('city');
            $table->jsonb('districts')->nullable();
            $table->unsignedInteger('price_min')->nullable();
            $table->unsignedInteger('price_max')->nullable();
            $table->unsignedInteger('area_min')->nullable();
            $table->unsignedInteger('area_max')->nullable();
            $table->unsignedSmallInteger('rooms_min')->nullable();
            $table->unsignedSmallInteger('rooms_max')->nullable();
            $table->jsonb('portals');
            $table->text('ai_preferences')->nullable();
            $table->jsonb('notify_channels');
            $table->unsignedSmallInteger('min_score_to_notify')->default(6);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('first_run_completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searches');
    }
};
