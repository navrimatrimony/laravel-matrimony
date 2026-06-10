<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->text('description')->nullable();
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('slug', 'suchak_plans_slug_unique');
            $table->index(['is_active', 'is_visible', 'sort_order'], 'suchak_plans_catalog_idx');
        });

        Schema::create('suchak_plan_features', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_plan_id');
            $table->string('feature_key', 120);
            $table->string('value_type', 32)->default('integer');
            $table->text('feature_value')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['suchak_plan_id', 'feature_key'], 'suchak_plan_features_plan_feature_unique');
            $table->index('suchak_plan_id', 'suchak_plan_features_plan_idx');
            $table->index('feature_key', 'suchak_plan_features_key_idx');
            $table->index('is_enabled', 'suchak_plan_features_enabled_idx');

            $table->foreign('suchak_plan_id', 'suchak_plan_features_plan_fk')->references('id')->on('suchak_plans')->restrictOnDelete();
        });

        Schema::create('suchak_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('suchak_plan_id');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'suchak_subscriptions_account_idx');
            $table->index('suchak_plan_id', 'suchak_subscriptions_plan_idx');
            $table->index('assigned_by_user_id', 'suchak_subscriptions_assigned_by_idx');
            $table->index(['suchak_account_id', 'status', 'starts_at', 'ends_at'], 'suchak_subscriptions_active_lookup_idx');
            $table->index('created_at', 'suchak_subscriptions_created_idx');

            $table->foreign('suchak_account_id', 'suchak_subscriptions_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('suchak_plan_id', 'suchak_subscriptions_plan_fk')->references('id')->on('suchak_plans')->restrictOnDelete();
            $table->foreign('assigned_by_user_id', 'suchak_subscriptions_assigned_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_subscriptions');
        Schema::dropIfExists('suchak_plan_features');
        Schema::dropIfExists('suchak_plans');
    }
};
