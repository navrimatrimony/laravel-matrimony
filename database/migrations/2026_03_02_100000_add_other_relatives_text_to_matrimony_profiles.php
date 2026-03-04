<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('matrimony_profiles', 'other_relatives_text')) {
                $table->text('other_relatives_text')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'other_relatives_text')) {
                $table->dropColumn('other_relatives_text');
            }
        });
    }
};
