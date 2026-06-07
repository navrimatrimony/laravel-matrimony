<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_siblings')) {
            return;
        }

        Schema::table('profile_siblings', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_siblings', 'address_line')) {
                $table->string('address_line', 255)->nullable()->after('city_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_siblings')) {
            return;
        }

        Schema::table('profile_siblings', function (Blueprint $table) {
            if (Schema::hasColumn('profile_siblings', 'address_line')) {
                $table->dropColumn('address_line');
            }
        });
    }
};
