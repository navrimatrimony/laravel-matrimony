<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add centralized Income Engine fields (additive only).
 * Personal: income_period, income_value_type, income_amount, income_min_amount, income_max_amount, income_normalized_annual_amount.
 * Family: family_income_period, family_income_value_type, family_income_amount, family_income_min_amount, family_income_max_amount,
 *         family_income_currency_id, family_income_private, family_income_normalized_annual_amount.
 * Existing columns (annual_income, income_range_id, family_income, income_currency_id, income_private) are NOT modified or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            // Personal income engine (structured)
            if (! Schema::hasColumn('matrimony_profiles', 'income_period')) {
                $table->string('income_period', 20)->nullable()->after('income_private');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'income_value_type')) {
                $table->string('income_value_type', 20)->nullable()->after('income_period');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'income_amount')) {
                $table->decimal('income_amount', 14, 2)->nullable()->after('income_value_type');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'income_min_amount')) {
                $table->decimal('income_min_amount', 14, 2)->nullable()->after('income_amount');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'income_max_amount')) {
                $table->decimal('income_max_amount', 14, 2)->nullable()->after('income_min_amount');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'income_normalized_annual_amount')) {
                $table->decimal('income_normalized_annual_amount', 14, 2)->nullable()->after('income_max_amount');
            }

            // Family income engine (structured)
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_period')) {
                $table->string('family_income_period', 20)->nullable()->after('family_income');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_value_type')) {
                $table->string('family_income_value_type', 20)->nullable()->after('family_income_period');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_amount')) {
                $table->decimal('family_income_amount', 14, 2)->nullable()->after('family_income_value_type');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_min_amount')) {
                $table->decimal('family_income_min_amount', 14, 2)->nullable()->after('family_income_amount');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_max_amount')) {
                $table->decimal('family_income_max_amount', 14, 2)->nullable()->after('family_income_min_amount');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_currency_id')) {
                $table->unsignedBigInteger('family_income_currency_id')->nullable()->after('family_income_max_amount');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_private')) {
                $table->boolean('family_income_private')->nullable()->after('family_income_currency_id');
            }
            if (! Schema::hasColumn('matrimony_profiles', 'family_income_normalized_annual_amount')) {
                $table->decimal('family_income_normalized_annual_amount', 14, 2)->nullable()->after('family_income_private');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $cols = [
                'income_period', 'income_value_type', 'income_amount', 'income_min_amount', 'income_max_amount', 'income_normalized_annual_amount',
                'family_income_period', 'family_income_value_type', 'family_income_amount', 'family_income_min_amount', 'family_income_max_amount',
                'family_income_currency_id', 'family_income_private', 'family_income_normalized_annual_amount',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('matrimony_profiles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
