<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: contact_preference = 'whatsapp'|'call'|'message' (icon choice; WhatsApp default).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_contacts')) {
            return;
        }
        Schema::table('profile_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_contacts', 'contact_preference')) {
                $table->string('contact_preference', 20)->nullable()->after('is_whatsapp');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_contacts')) {
            return;
        }
        Schema::table('profile_contacts', function (Blueprint $table) {
            if (Schema::hasColumn('profile_contacts', 'contact_preference')) {
                $table->dropColumn('contact_preference');
            }
        });
    }
};
