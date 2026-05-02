<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin approval intelligence: type/parent hints, unified location resolution, analysis payload.
 * Additive only (PHASE-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_open_place_suggestions')) {
            return;
        }

        Schema::table('location_open_place_suggestions', function (Blueprint $table) {
            if (! Schema::hasColumn('location_open_place_suggestions', 'suggested_type')) {
                $table->string('suggested_type', 32)->nullable()->after('match_type');
            }
            if (! Schema::hasColumn('location_open_place_suggestions', 'suggested_parent_id')) {
                $table->unsignedBigInteger('suggested_parent_id')->nullable()->after('suggested_type');
            }
            if (! Schema::hasColumn('location_open_place_suggestions', 'resolved_location_id')) {
                $table->unsignedBigInteger('resolved_location_id')->nullable()->after('resolved_city_id');
            }
            if (! Schema::hasColumn('location_open_place_suggestions', 'analysis_json')) {
                $table->json('analysis_json')->nullable()->after('confidence_score');
            }
        });

        if (Schema::hasTable('locations')) {
            Schema::table('location_open_place_suggestions', function (Blueprint $table) {
                if (Schema::hasColumn('location_open_place_suggestions', 'suggested_parent_id')) {
                    $table->foreign('suggested_parent_id', 'lop_sugg_parent_loc_fk')
                        ->references('id')
                        ->on('locations')
                        ->nullOnDelete();
                }
                if (Schema::hasColumn('location_open_place_suggestions', 'resolved_location_id')) {
                    $table->foreign('resolved_location_id', 'lop_sugg_resolved_loc_fk')
                        ->references('id')
                        ->on('locations')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('location_open_place_suggestions')) {
            return;
        }

        Schema::table('location_open_place_suggestions', function (Blueprint $table) {
            if (Schema::hasColumn('location_open_place_suggestions', 'suggested_parent_id')) {
                try {
                    $table->dropForeign('lop_sugg_parent_loc_fk');
                } catch (\Throwable) {
                }
            }
            if (Schema::hasColumn('location_open_place_suggestions', 'resolved_location_id')) {
                try {
                    $table->dropForeign('lop_sugg_resolved_loc_fk');
                } catch (\Throwable) {
                }
            }
        });

        Schema::table('location_open_place_suggestions', function (Blueprint $table) {
            $cols = ['suggested_type', 'suggested_parent_id', 'resolved_location_id', 'analysis_json'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('location_open_place_suggestions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
