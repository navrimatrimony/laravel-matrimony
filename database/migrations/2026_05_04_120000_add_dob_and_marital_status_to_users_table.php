<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Engine registration: store DOB and marital status on users until profile is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable()->after('gender');
            }
            if (! Schema::hasColumn('users', 'marital_status')) {
                $table->string('marital_status', 64)->nullable()->after('dob');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'marital_status')) {
                $table->dropColumn('marital_status');
            }
            if (Schema::hasColumn('users', 'dob')) {
                $table->dropColumn('dob');
            }
        });
    }
};
