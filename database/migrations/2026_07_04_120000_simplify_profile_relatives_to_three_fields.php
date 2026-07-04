<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_relatives')) {
            return;
        }

        Schema::table('profile_relatives', function (Blueprint $table): void {
            if (! Schema::hasColumn('profile_relatives', 'relative_details')) {
                $table->text('relative_details')->nullable()->after('relation_type');
            }
        });

        foreach (['occupation_master_id', 'occupation_custom_id', 'city_id', 'state_id'] as $column) {
            $this->dropForeignIfExists('profile_relatives', $column);
        }

        $dropColumns = array_values(array_filter([
            'name',
            'occupation',
            'marital_status',
            'occupation_master_id',
            'occupation_custom_id',
            'city_id',
            'state_id',
            'address_line',
            'taluka_id',
            'district_id',
            'notes',
            'is_primary_contact',
        ], fn (string $column): bool => Schema::hasColumn('profile_relatives', $column)));

        if ($dropColumns !== []) {
            Schema::table('profile_relatives', function (Blueprint $table) use ($dropColumns): void {
                $table->dropColumn($dropColumns);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_relatives')) {
            return;
        }

        Schema::table('profile_relatives', function (Blueprint $table): void {
            if (! Schema::hasColumn('profile_relatives', 'name')) {
                $table->string('name')->nullable()->after('relation_type');
            }
            if (! Schema::hasColumn('profile_relatives', 'occupation')) {
                $table->string('occupation')->nullable()->after('name');
            }
            if (! Schema::hasColumn('profile_relatives', 'marital_status')) {
                $table->string('marital_status')->nullable()->after('occupation');
            }
            if (! Schema::hasColumn('profile_relatives', 'city_id')) {
                $table->unsignedBigInteger('city_id')->nullable()->after('marital_status');
            }
            if (! Schema::hasColumn('profile_relatives', 'state_id')) {
                $table->unsignedBigInteger('state_id')->nullable()->after('city_id');
            }
            if (! Schema::hasColumn('profile_relatives', 'address_line')) {
                $table->string('address_line', 255)->nullable()->after('state_id');
            }
            if (! Schema::hasColumn('profile_relatives', 'taluka_id')) {
                $table->unsignedBigInteger('taluka_id')->nullable()->after('address_line');
            }
            if (! Schema::hasColumn('profile_relatives', 'district_id')) {
                $table->unsignedBigInteger('district_id')->nullable()->after('taluka_id');
            }
            if (! Schema::hasColumn('profile_relatives', 'notes')) {
                $table->text('notes')->nullable()->after('contact_number');
            }
            if (! Schema::hasColumn('profile_relatives', 'is_primary_contact')) {
                $table->boolean('is_primary_contact')->default(false)->after('notes');
            }
            if (Schema::hasColumn('profile_relatives', 'relative_details')) {
                $table->dropColumn('relative_details');
            }
        });
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (Throwable) {
            //
        }
    }
};
