<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.2: merge duplicate open-place rows into one pending bucket (usage aggregate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_open_place_suggestions')) {
            return;
        }
        if (Schema::hasColumn('location_open_place_suggestions', 'merged_into_suggestion_id')) {
            return;
        }
        Schema::table('location_open_place_suggestions', function (Blueprint $table) {
          $table->unsignedBigInteger('merged_into_suggestion_id')
    ->nullable()
    ->after('admin_reviewed_at');

$table->foreign(
    'merged_into_suggestion_id',
    'lop_suggestions_merged_fk' // short name (IMPORTANT)
)->references('id')
 ->on('location_open_place_suggestions')
 ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('location_open_place_suggestions')) {
            return;
        }
        if (! Schema::hasColumn('location_open_place_suggestions', 'merged_into_suggestion_id')) {
            return;
        }
        Schema::table('location_open_place_suggestions', function (Blueprint $table) {
            $table->dropForeign('lop_suggestions_merged_fk');
$table->dropColumn('merged_into_suggestion_id');
        });
    }
};
