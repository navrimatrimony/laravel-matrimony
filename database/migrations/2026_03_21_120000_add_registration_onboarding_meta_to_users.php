<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-level registration metadata (who is being registered for).
 * Not duplicate matrimony profile fields — stored on users for account context only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'registering_for')) {
                $table->string('registering_for', 32)->nullable()->after('gender');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'registering_for')) {
                $table->dropColumn('registering_for');
            }
        });
    }
};
