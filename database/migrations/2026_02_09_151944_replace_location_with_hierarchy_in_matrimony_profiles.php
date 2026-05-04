<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace free-text location with hierarchy IDs pointing into {@code addresses} (same id space).
 * Legacy columns ({@code country_id} … {@code city_id}) stay nullable without FKs for backward compatibility;
 * canonical residence is {@code location_id} → addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn('location');
        });

        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable()->after('education');
            $table->unsignedBigInteger('state_id')->nullable()->after('country_id');
            $table->unsignedBigInteger('district_id')->nullable()->after('state_id');
            $table->unsignedBigInteger('taluka_id')->nullable()->after('district_id');
            $table->unsignedBigInteger('city_id')->nullable()->after('taluka_id');
            $table->unsignedBigInteger('location_id')->nullable()->after('city_id');
        });

        if (Schema::hasTable('addresses')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->foreign('location_id')->references('id')->on('addresses')->nullOnDelete();
            });
        }

        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->index('country_id');
            $table->index('state_id');
            $table->index('district_id');
            $table->index('taluka_id');
            $table->index('city_id');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
                try {
                    $table->dropForeign(['location_id']);
                } catch (\Throwable) {
                }
            }
        });

        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $drops = array_filter([
                Schema::hasColumn('matrimony_profiles', 'country_id') ? 'country_id' : null,
                Schema::hasColumn('matrimony_profiles', 'state_id') ? 'state_id' : null,
                Schema::hasColumn('matrimony_profiles', 'district_id') ? 'district_id' : null,
                Schema::hasColumn('matrimony_profiles', 'taluka_id') ? 'taluka_id' : null,
                Schema::hasColumn('matrimony_profiles', 'city_id') ? 'city_id' : null,
                Schema::hasColumn('matrimony_profiles', 'location_id') ? 'location_id' : null,
            ]);
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });

        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->string('location')->nullable()->after('education');
        });
    }
};
