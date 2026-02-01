<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('matrimony_profiles', 'height_cm')) {
                $table->unsignedSmallInteger('height_cm')->nullable()->after('location');
            }
            if (!Schema::hasColumn('matrimony_profiles', 'visibility_override')) {
                if (Schema::hasColumn('matrimony_profiles', 'is_demo')) {
                    $table->boolean('visibility_override')->default(false)->after('is_demo');
                } else {
                    $table->boolean('visibility_override')->default(false);
                }
            }
            if (!Schema::hasColumn('matrimony_profiles', 'visibility_override_reason')) {
                $table->text('visibility_override_reason')->nullable()->after('visibility_override');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('matrimony_profiles', 'height_cm')) $cols[] = 'height_cm';
            if (Schema::hasColumn('matrimony_profiles', 'visibility_override')) $cols[] = 'visibility_override';
            if (Schema::hasColumn('matrimony_profiles', 'visibility_override_reason')) $cols[] = 'visibility_override_reason';
            if ($cols !== []) $table->dropColumn($cols);
        });
    }
};
