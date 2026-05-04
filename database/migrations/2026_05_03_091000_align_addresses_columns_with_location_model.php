<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * {@code addresses} is the SSOT table; align optional columns used by {@see \App\Models\Location}.
 * User installs may already have {@code tag}, {@code pincode}, etc.; only add what is missing.
 * When both {@code category} (from older locations migrations) and {@code tag} exist, copy into {@code tag}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $table): void {
            if (! Schema::hasColumn('addresses', 'iso_alpha2')) {
                $table->string('iso_alpha2', 2)->nullable()->index();
            }
            if (! Schema::hasColumn('addresses', 'tag')) {
                $table->string('tag', 32)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'name_mr')) {
                $table->string('name_mr')->nullable();
            }
            if (! Schema::hasColumn('addresses', 'name_en')) {
                $table->string('name_en')->nullable();
            }
            if (! Schema::hasColumn('addresses', 'state_code')) {
                $table->string('state_code', 32)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'district_code')) {
                $table->string('district_code', 32)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'pincode')) {
                $table->string('pincode', 16)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'lgd_code')) {
                $table->string('lgd_code', 32)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'population')) {
                $table->unsignedBigInteger('population')->nullable();
            }
        });

        if (Schema::hasColumn('addresses', 'category') && Schema::hasColumn('addresses', 'tag')) {
            DB::table('addresses')->whereNull('tag')->update([
                'tag' => DB::raw('category'),
            ]);
        }
    }

    public function down(): void
    {
        // Additive-only migration: do not drop user data columns.
    }
};
