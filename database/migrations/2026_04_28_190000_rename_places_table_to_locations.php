<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('places') && ! Schema::hasTable('locations')) {
            Schema::rename('places', 'locations');
        }

        if (Schema::hasTable('pincodes') && Schema::hasColumn('pincodes', 'place_id')) {
            Schema::table('pincodes', function (Blueprint $table) {
                try {
                    $table->dropForeign(['place_id']);
                } catch (Throwable $e) {
                    // Foreign key may not exist yet in some environments.
                }
            });

            Schema::table('pincodes', function (Blueprint $table) {
                $table->foreign('place_id')->references('id')->on('locations')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pincodes') && Schema::hasColumn('pincodes', 'place_id')) {
            Schema::table('pincodes', function (Blueprint $table) {
                try {
                    $table->dropForeign(['place_id']);
                } catch (Throwable $e) {
                    // Ignore when FK is absent.
                }
            });
        }

        if (Schema::hasTable('locations') && ! Schema::hasTable('places')) {
            Schema::rename('locations', 'places');
        }

        if (Schema::hasTable('pincodes') && Schema::hasColumn('pincodes', 'place_id')) {
            Schema::table('pincodes', function (Blueprint $table) {
                $table->foreign('place_id')->references('id')->on('places')->cascadeOnDelete();
            });
        }
    }
};

