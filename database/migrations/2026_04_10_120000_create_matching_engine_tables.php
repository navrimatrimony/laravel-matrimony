<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matching_engine_configs', function (Blueprint $table) {
            $table->id();
            $table->string('config_key', 128)->unique();
            $table->json('config_value');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('matching_fields', function (Blueprint $table) {
            $table->id();
            $table->string('field_key', 64)->unique();
            $table->string('label', 191);
            $table->string('type', 32)->default('similarity');
            $table->string('category', 32)->default('core');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('weight')->default(0);
            $table->unsignedSmallInteger('max_weight')->default(100);
            $table->timestamps();
        });

        Schema::create('matching_hard_filters', function (Blueprint $table) {
            $table->id();
            $table->string('filter_key', 64)->unique();
            $table->string('mode', 16)->default('off');
            $table->unsignedSmallInteger('preferred_penalty_points')->default(10);
            $table->timestamps();
        });

        Schema::create('matching_behavior_weights', function (Blueprint $table) {
            $table->id();
            $table->string('action', 32)->unique();
            $table->integer('weight')->default(0);
            $table->unsignedSmallInteger('decay_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('matching_boost_rules', function (Blueprint $table) {
            $table->id();
            $table->string('boost_type', 32)->unique();
            $table->integer('value')->default(0);
            $table->unsignedSmallInteger('max_cap')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('matching_config_versions', function (Blueprint $table) {
            $table->id();
            $table->json('config_snapshot');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('user_match_behaviors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->string('action', 32);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_user_id', 'target_profile_id']);
            $table->index(['target_profile_id', 'action']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_match_behaviors');
        Schema::dropIfExists('matching_config_versions');
        Schema::dropIfExists('matching_boost_rules');
        Schema::dropIfExists('matching_behavior_weights');
        Schema::dropIfExists('matching_hard_filters');
        Schema::dropIfExists('matching_fields');
        Schema::dropIfExists('matching_engine_configs');
    }
};
