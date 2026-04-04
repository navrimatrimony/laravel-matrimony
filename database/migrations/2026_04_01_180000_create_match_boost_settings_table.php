<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('match_boost_settings')) {
            return;
        }

        Schema::create('match_boost_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('use_ai')->default(false);
            $table->string('ai_provider', 32)->nullable();
            $table->string('ai_model', 64)->nullable();
            $table->unsignedSmallInteger('boost_active_weight')->default(3);
            $table->unsignedSmallInteger('boost_premium_weight')->default(2);
            $table->unsignedSmallInteger('boost_similarity_weight')->default(3);
            $table->unsignedSmallInteger('max_boost_limit')->default(20);
            $table->unsignedSmallInteger('boost_gold_extra')->default(10);
            $table->unsignedSmallInteger('boost_silver_extra')->default(5);
            $table->unsignedSmallInteger('active_within_days')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_boost_settings');
    }
};
