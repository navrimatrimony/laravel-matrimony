<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_feature_configs')) {
            return;
        }

        Schema::create('plan_feature_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();

            $table->string('feature_key');

            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_unlimited')->default(false);

            $table->integer('limit_total')->nullable();
            $table->string('period')->nullable();

            $table->integer('daily_cap')->nullable();

            $table->integer('soft_limit_percent')->nullable();

            $table->integer('expiry_days')->nullable();

            $table->integer('extra_cost_per_action')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature_configs');
    }
};
