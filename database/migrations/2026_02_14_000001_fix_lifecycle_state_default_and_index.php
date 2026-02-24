<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->string('lifecycle_state', 32)->default('active')->change();
            $table->index('lifecycle_state');
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_state']);
            $table->string('lifecycle_state', 32)->default('active')->change();
        });
    }
};
