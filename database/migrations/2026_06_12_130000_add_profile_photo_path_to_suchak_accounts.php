<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suchak_accounts') || Schema::hasColumn('suchak_accounts', 'profile_photo_path')) {
            return;
        }

        Schema::table('suchak_accounts', function (Blueprint $table): void {
            $table->string('profile_photo_path')->nullable()->after('address_line_mr');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suchak_accounts') || ! Schema::hasColumn('suchak_accounts', 'profile_photo_path')) {
            return;
        }

        Schema::table('suchak_accounts', function (Blueprint $table): void {
            $table->dropColumn('profile_photo_path');
        });
    }
};
