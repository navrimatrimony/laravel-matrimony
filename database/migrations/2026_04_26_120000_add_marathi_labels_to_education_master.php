<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_categories', function (Blueprint $table) {
            $table->string('name_mr', 128)->nullable()->after('name');
        });

        Schema::table('education_degrees', function (Blueprint $table) {
            $table->string('code_mr', 128)->nullable()->after('code');
            $table->string('title_mr', 255)->nullable()->after('title');
            $table->text('full_form_mr')->nullable()->after('full_form');
        });
    }

    public function down(): void
    {
        Schema::table('education_categories', function (Blueprint $table) {
            $table->dropColumn('name_mr');
        });

        Schema::table('education_degrees', function (Blueprint $table) {
            $table->dropColumn(['code_mr', 'title_mr', 'full_form_mr']);
        });
    }
};
