<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-level registration metadata (who is being registered for, relation).
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
            if (! Schema::hasColumn('users', 'relation_to_profile')) {
                $table->string('relation_to_profile', 32)->nullable()->after('registering_for');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'relation_to_profile')) {
                $table->dropColumn('relation_to_profile');
            }
            if (Schema::hasColumn('users', 'registering_for')) {
                $table->dropColumn('registering_for');
            }
        });
    }
};
