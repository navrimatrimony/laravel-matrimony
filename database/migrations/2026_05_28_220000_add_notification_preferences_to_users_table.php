<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'notification_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_preferences')->nullable()->after('preferred_locale');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'notification_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });
    }
};
