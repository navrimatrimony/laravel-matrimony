<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: monetization / catalog fields. Uses {@see Schema::hasColumn} for production safety.
 *
 * Note: {@code plans.highlight} already exists (boolean) — do not add {@code is_highlighted}.
 * Note: {@code plan_terms.is_visible} controls per–billing-period visibility; {@code plans.is_visible}
 * is plan-level “offer this tier on pricing” when present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plans')) {
            Schema::table('plans', function (Blueprint $table) {
                if (! Schema::hasColumn('plans', 'tier')) {
                    $table->unsignedTinyInteger('tier')->nullable()->after('slug');
                }
                if (! Schema::hasColumn('plans', 'description')) {
                    $table->text('description')->nullable()->after('name');
                }
                if (! Schema::hasColumn('plans', 'is_visible')) {
                    $table->boolean('is_visible')->default(true)->after('is_active');
                }
            });
        }

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('subscriptions', 'meta')) {
                    $table->json('meta')->nullable()->after('status');
                }
            });
        }

        if (Schema::hasTable('user_feature_usages')) {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                if (! Schema::hasColumn('user_feature_usages', 'reset_at')) {
                    $table->timestamp('reset_at')->nullable()->after('period_end');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plans')) {
            Schema::table('plans', function (Blueprint $table) {
                if (Schema::hasColumn('plans', 'tier')) {
                    $table->dropColumn('tier');
                }
                if (Schema::hasColumn('plans', 'description')) {
                    $table->dropColumn('description');
                }
                if (Schema::hasColumn('plans', 'is_visible')) {
                    $table->dropColumn('is_visible');
                }
            });
        }

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (Schema::hasColumn('subscriptions', 'meta')) {
                    $table->dropColumn('meta');
                }
            });
        }

        if (Schema::hasTable('user_feature_usages')) {
            Schema::table('user_feature_usages', function (Blueprint $table) {
                if (Schema::hasColumn('user_feature_usages', 'reset_at')) {
                    $table->dropColumn('reset_at');
                }
            });
        }
    }
};
