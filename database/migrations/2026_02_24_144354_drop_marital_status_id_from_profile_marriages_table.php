<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropMaritalStatusIdFromProfileMarriagesTable extends Migration
{
    public function up(): void
    {
        Schema::table('profile_marriages', function (Blueprint $table) {

            if (Schema::hasColumn('profile_marriages', 'marital_status_id')) {

                $table->dropForeign(['marital_status_id']);
                $table->dropColumn('marital_status_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_marriages', function (Blueprint $table) {
            $table->unsignedBigInteger('marital_status_id')->nullable();
        });
    }
}