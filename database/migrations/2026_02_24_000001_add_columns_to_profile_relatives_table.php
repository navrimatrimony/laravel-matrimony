<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_relatives')) {
            return;
        }
        Schema::table('profile_relatives', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_relatives', 'city_id')) {
                $table->foreignId('city_id')->nullable()->after('occupation')->constrained('cities')->nullOnDelete();
            }
            if (! Schema::hasColumn('profile_relatives', 'state_id')) {
                $table->foreignId('state_id')->nullable()->after('city_id')->constrained('states')->nullOnDelete();
            }
            if (! Schema::hasColumn('profile_relatives', 'contact_number')) {
                $table->string('contact_number')->nullable()->after('state_id');
            }
            if (! Schema::hasColumn('profile_relatives', 'is_primary_contact')) {
                $table->boolean('is_primary_contact')->default(false)->after('notes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_relatives')) {
            return;
        }
        Schema::table('profile_relatives', function (Blueprint $table) {
            if (Schema::hasColumn('profile_relatives', 'city_id')) {
                $table->dropForeign(['city_id']);
            }
            if (Schema::hasColumn('profile_relatives', 'state_id')) {
                $table->dropForeign(['state_id']);
            }
            if (Schema::hasColumn('profile_relatives', 'contact_number')) {
                $table->dropColumn('contact_number');
            }
            if (Schema::hasColumn('profile_relatives', 'is_primary_contact')) {
                $table->dropColumn('is_primary_contact');
            }
        });
    }
};
