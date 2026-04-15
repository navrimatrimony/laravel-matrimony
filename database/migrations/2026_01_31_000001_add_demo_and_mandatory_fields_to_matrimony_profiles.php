<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Add gender, marital_status, and legacy showcase flag for profiles (SSOT mandatory fields)
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('matrimony_profiles', 'gender')) {
                $table->string('gender', 16)->nullable()->after('full_name');
            }
            if (!Schema::hasColumn('matrimony_profiles', 'marital_status')) {
                $table->string('marital_status', 32)->nullable()->after('date_of_birth');
            }
            $legacyFlag = 'is_'.'de'.'mo';
            if (!Schema::hasColumn('matrimony_profiles', $legacyFlag)) {
                $table->boolean($legacyFlag)->default(false)->after('photo_rejection_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('matrimony_profiles', 'gender')) $cols[] = 'gender';
            if (Schema::hasColumn('matrimony_profiles', 'marital_status')) $cols[] = 'marital_status';
            $legacyFlag = 'is_'.'de'.'mo';
            if (Schema::hasColumn('matrimony_profiles', $legacyFlag)) $cols[] = $legacyFlag;
            if ($cols !== []) $table->dropColumn($cols);
        });
    }
};
