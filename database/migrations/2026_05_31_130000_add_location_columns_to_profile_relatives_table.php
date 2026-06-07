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

        Schema::table('profile_relatives', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_relatives', 'address_line')) {
                $table->string('address_line', 255)->nullable()->after('state_id');
            }
            if (! Schema::hasColumn('profile_relatives', 'taluka_id')) {
                $table->unsignedBigInteger('taluka_id')->nullable()->after('address_line');
            }
            if (! Schema::hasColumn('profile_relatives', 'district_id')) {
                $table->unsignedBigInteger('district_id')->nullable()->after('taluka_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_relatives')) {
            return;
        }

        Schema::table('profile_relatives', function (Blueprint $table) {
            foreach (['district_id', 'taluka_id', 'address_line'] as $col) {
                if (Schema::hasColumn('profile_relatives', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
