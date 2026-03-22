<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $name = 'profile_preference_criteria';
        if (! Schema::hasTable($name)) {
            return;
        }
        Schema::table($name, function (Blueprint $table) use ($name) {
            if (! Schema::hasColumn($name, 'partner_profile_with_children')) {
                $table->string('partner_profile_with_children', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        $name = 'profile_preference_criteria';
        if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'partner_profile_with_children')) {
            return;
        }
        Schema::table($name, function (Blueprint $table) {
            $table->dropColumn('partner_profile_with_children');
        });
    }
};
