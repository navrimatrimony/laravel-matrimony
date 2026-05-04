<?php

use App\Services\Location\LocationSsotNormalizationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('matrimony_profiles') && Schema::hasTable('addresses')
            && ! Schema::hasColumn('matrimony_profiles', 'location_id')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('location_id')->nullable()->after('city_id');
            });

            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->foreign('location_id')->references('id')->on('addresses')->nullOnDelete();
            });
        }

        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'location_id')
            && Schema::hasTable('addresses')) {
            DB::statement(
                'UPDATE matrimony_profiles SET location_id = city_id '
                .'WHERE city_id IS NOT NULL AND location_id IS NULL '
                .'AND EXISTS (SELECT 1 FROM addresses WHERE addresses.id = matrimony_profiles.city_id)'
            );
        }

        if (Schema::hasTable('addresses')) {
            /** @var LocationSsotNormalizationService $normalize */
            $normalize = app(LocationSsotNormalizationService::class);
            $normalize->normalizeNamesSlugsLevels();
            $normalize->deduplicateParents();
            try {
                $normalize->addUniqueIdentityConstraint();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'location_id')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                try {
                    $table->dropForeign(['location_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('location_id');
            });
        }

        if (Schema::hasTable('addresses') && Schema::hasIndex('addresses', 'addresses_parent_name_type_unique')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropUnique('addresses_parent_name_type_unique');
            });
        }
    }
};
