<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing SSOT: MRP (`price`) + payable (`selling_price`).
 * `discount_percent` is retained deprecated (display may be derived; never charge from it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_terms') && ! Schema::hasColumn('plan_terms', 'selling_price')) {
            Schema::table('plan_terms', function (Blueprint $table) {
                $table->decimal('selling_price', 10, 2)->nullable()->after('price');
            });
        }

        if (Schema::hasTable('plans') && ! Schema::hasColumn('plans', 'selling_price')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->decimal('selling_price', 10, 2)->nullable()->after('price');
            });
        }

        // Backfill from legacy final_price formula so payable amounts stay exact.
        if (Schema::hasTable('plan_terms') && Schema::hasColumn('plan_terms', 'selling_price')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                DB::statement('
                    UPDATE plan_terms
                    SET selling_price = ROUND(
                        CASE
                            WHEN discount_percent IS NOT NULL AND discount_percent > 0
                            THEN price * (1.0 - (CAST(discount_percent AS REAL) / 100.0))
                            ELSE price
                        END
                    , 2)
                    WHERE selling_price IS NULL
                ');
            } else {
                DB::statement('
                    UPDATE plan_terms
                    SET selling_price = ROUND(
                        CASE
                            WHEN discount_percent IS NOT NULL AND discount_percent > 0
                            THEN price * (1 - (discount_percent / 100))
                            ELSE price
                        END
                    , 2)
                    WHERE selling_price IS NULL
                ');
            }
        }

        if (Schema::hasTable('plans') && Schema::hasColumn('plans', 'selling_price')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                DB::statement('
                    UPDATE plans
                    SET selling_price = ROUND(
                        CASE
                            WHEN discount_percent IS NOT NULL AND discount_percent > 0
                            THEN price * (1.0 - (CAST(discount_percent AS REAL) / 100.0))
                            ELSE price
                        END
                    , 2)
                    WHERE selling_price IS NULL
                ');
            } else {
                DB::statement('
                    UPDATE plans
                    SET selling_price = ROUND(
                        CASE
                            WHEN discount_percent IS NOT NULL AND discount_percent > 0
                            THEN price * (1 - (discount_percent / 100))
                            ELSE price
                        END
                    , 2)
                    WHERE selling_price IS NULL
                ');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plan_terms') && Schema::hasColumn('plan_terms', 'selling_price')) {
            Schema::table('plan_terms', function (Blueprint $table) {
                $table->dropColumn('selling_price');
            });
        }

        if (Schema::hasTable('plans') && Schema::hasColumn('plans', 'selling_price')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('selling_price');
            });
        }
    }
};
