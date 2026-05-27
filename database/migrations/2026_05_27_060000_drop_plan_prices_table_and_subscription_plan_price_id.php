<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'plan_price_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('plan_price_id');
            });
        }

        Schema::dropIfExists('plan_prices');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('plan_prices')) {
            Schema::create('plan_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->string('duration_type', 32);
                $table->unsignedInteger('duration_days');
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('original_price', 12, 2)->nullable();
                $table->unsignedTinyInteger('discount_percent')->nullable();
                $table->boolean('is_popular')->default(false);
                $table->boolean('is_best_seller')->default(false);
                $table->string('tag', 120)->nullable();
                $table->boolean('is_visible')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['plan_id', 'duration_type']);
                $table->index(['plan_id', 'is_visible', 'sort_order']);
            });
        }

        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'plan_price_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreignId('plan_price_id')
                    ->nullable()
                    ->after('plan_term_id')
                    ->constrained('plan_prices')
                    ->nullOnDelete();
            });
        }
    }
};
