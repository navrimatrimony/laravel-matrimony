<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Add Profile Photo to Matrimony Profiles Table
|--------------------------------------------------------------------------
| 👉 MatrimonyProfile साठी single profile photo
| 👉 User model शी काहीही संबंध नाही
| 👉 Phase-1 basic implementation
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            // 🔴 Profile photo path (storage path)
            $table->string('profile_photo')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn('profile_photo');
        });
    }
};
