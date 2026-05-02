<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $allowed = ['metro', 'city', 'town', 'village', 'suburban'];

    public function up(): void
    {
        if (! Schema::hasTable('locations') || ! Schema::hasColumn('locations', 'category')) {
            return;
        }

        // Production-safe normalization before strict DB constraint.
        DB::table('locations')
            ->whereNull('category')
            ->orWhereNotIn('category', $this->allowed)
            ->update(['category' => 'village']);

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            if (Schema::hasIndex('locations', 'locations_category_index')) {
                Schema::table('locations', function (Blueprint $table) {
                    $table->dropIndex(['category']);
                });
            }

            DB::statement(
                "ALTER TABLE locations MODIFY category ENUM('metro','city','town','village','suburban') NOT NULL DEFAULT 'village'"
            );

            if (! Schema::hasIndex('locations', 'locations_category_index')) {
                Schema::table('locations', function (Blueprint $table) {
                    $table->index('category');
                });
            }

            return;
        }

        // Non-MySQL fallback: keep string, enforce data cleanliness + default + index.
        if (! Schema::hasIndex('locations', 'locations_category_index')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->index('category');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('locations') || ! Schema::hasColumn('locations', 'category')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE locations MODIFY category VARCHAR(32) NULL");
        }

        if (! Schema::hasIndex('locations', 'locations_category_index')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->index('category');
            });
        }
    }
};

