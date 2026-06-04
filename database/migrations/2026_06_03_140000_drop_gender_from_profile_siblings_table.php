<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_siblings', function (Blueprint $table) {
            if (Schema::hasColumn('profile_siblings', 'gender')) {
                $table->dropColumn('gender');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_siblings', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_siblings', 'gender')) {
                $table->string('gender', 20)->nullable()->after('name');
            }
        });
    }
};
