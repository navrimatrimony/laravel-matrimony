<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_castes', function (Blueprint $table) {
            $table->string('status', 32)->default('approved')->after('is_active');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('status');
            $table->unsignedBigInteger('approved_by_admin_id')->nullable()->after('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sub_castes', function (Blueprint $table) {
            $table->dropColumn(['status', 'created_by_user_id', 'approved_by_admin_id']);
        });
    }
};
