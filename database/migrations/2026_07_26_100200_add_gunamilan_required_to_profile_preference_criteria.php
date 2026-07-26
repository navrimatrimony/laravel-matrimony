<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Only show me matches whose गुणमिलन / Gunamilan works out."
 *
 * DATA + MODEL ONLY. Nothing reads this column yet — no API field, no
 * validation, no UI, no matching filter. Wiring it into the matching pipeline
 * is a separate task; the column exists first so that task has one settled home
 * to bind to instead of inventing a second one.
 *
 * Named `gunamilan_required` to match the terminology the product already ships
 * (पत्रिका / गुणमिलन, never "कुंडली"). Default FALSE: gunamilan is optional, and
 * the horoscope section is optional too, so a seeker who never opened it must
 * not silently acquire a filter that hides most of the pool.
 *
 * When it IS wired, the rule is fixed by `GunamilanService`: `computable`
 * false means UNKNOWN and must not reject a candidate — only a computable score
 * below `GunamilanService::COMPATIBLE_THRESHOLD` (18 of 36, inclusive) may.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_preference_criteria')
            || Schema::hasColumn('profile_preference_criteria', 'gunamilan_required')) {
            return;
        }

        Schema::table('profile_preference_criteria', function (Blueprint $table): void {
            $table->boolean('gunamilan_required')
                ->default(false)
                ->after('partner_profile_with_children');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_preference_criteria')
            || ! Schema::hasColumn('profile_preference_criteria', 'gunamilan_required')) {
            return;
        }

        Schema::table('profile_preference_criteria', function (Blueprint $table): void {
            $table->dropColumn('gunamilan_required');
        });
    }
};
