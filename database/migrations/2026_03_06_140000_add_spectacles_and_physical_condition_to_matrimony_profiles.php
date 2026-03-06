<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical Engine: additive columns only (PHASE-5).
 * spectacles_lens: No | Spectacles | Contact Lens | Both
 * physical_condition: None | Physically Challenged | Hearing | Vision | Other | Prefer Not To Say
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'spectacles_lens')) {
                $table->string('spectacles_lens', 50)->nullable()->after('blood_group_id');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'physical_condition')) {
                $table->string('physical_condition', 50)->nullable()->after('spectacles_lens');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'physical_condition')) {
                $table->dropColumn('physical_condition');
            }
            if (Schema::hasColumn('matrimony_profiles', 'spectacles_lens')) {
                $table->dropColumn('spectacles_lens');
            }
        });
    }
};
