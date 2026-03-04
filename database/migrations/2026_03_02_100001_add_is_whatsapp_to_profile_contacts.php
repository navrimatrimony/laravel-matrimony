<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_contacts')) {
            return;
        }
        Schema::table('profile_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_contacts', 'is_whatsapp')) {
                $table->boolean('is_whatsapp')->default(false)->after('phone_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_contacts')) {
            return;
        }
        Schema::table('profile_contacts', function (Blueprint $table) {
            if (Schema::hasColumn('profile_contacts', 'is_whatsapp')) {
                $table->dropColumn('is_whatsapp');
            }
        });
    }
};
