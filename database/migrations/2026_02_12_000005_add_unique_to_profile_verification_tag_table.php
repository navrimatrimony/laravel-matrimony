<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_verification_tag', function (Blueprint $table) {
            $table->unique(['matrimony_profile_id', 'verification_tag_id'], 'pvt_profile_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::table('profile_verification_tag', function (Blueprint $table) {
            $table->dropUnique('pvt_profile_tag_unique');
        });
    }
};
