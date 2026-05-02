<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage feedback loop + canonical aliases for {@see Location} (additive, PHASE-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locations')) {
            return;
        }

        if (! Schema::hasTable('location_usage_stats')) {
            Schema::create('location_usage_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('location_id')->unique()->constrained('locations')->cascadeOnDelete();
                $table->unsignedInteger('usage_count')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->index(['usage_count', 'last_used_at'], 'lus_popularity_idx');
            });
        }

        if (! Schema::hasTable('location_aliases')) {
            Schema::create('location_aliases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
                $table->string('alias', 255);
                $table->string('normalized_alias', 255);
                $table->timestamps();

                $table->index('normalized_alias', 'loc_alias_norm_idx');
                $table->unique(['location_id', 'normalized_alias'], 'loc_alias_unique_loc_norm');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_aliases');
        Schema::dropIfExists('location_usage_stats');
    }
};
