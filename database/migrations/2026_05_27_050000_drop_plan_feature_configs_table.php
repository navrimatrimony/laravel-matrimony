<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('plan_feature_configs');
    }

    public function down(): void
    {
        if (Schema::hasTable('plan_feature_configs')) {
            return;
        }

        Schema::create('plan_feature_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 80);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_unlimited')->default(false);
            $table->unsignedInteger('limit_total')->nullable();
            $table->string('period', 32)->nullable();
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedTinyInteger('soft_limit_percent')->nullable();
            $table->unsignedInteger('expiry_days')->nullable();
            $table->unsignedInteger('extra_cost_per_action')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
            $table->index('feature_key');
        });
    }
};
