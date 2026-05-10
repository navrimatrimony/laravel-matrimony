<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_addresses')) {
            return;
        }

        if (! Schema::hasColumn('profile_addresses', 'address_scope')) {
            Schema::table('profile_addresses', function (Blueprint $table): void {
                $table->string('address_scope', 16)->default('self')->after('profile_id');
            });
        }

        if (! Schema::hasColumn('profile_addresses', 'address_line')) {
            Schema::table('profile_addresses', function (Blueprint $table): void {
                $table->string('address_line', 255)->nullable()->after('postal_code');
            });
        }

        DB::table('profile_addresses')->whereNull('address_scope')->update(['address_scope' => 'self']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_addresses')) {
            return;
        }

        if (Schema::hasColumn('profile_addresses', 'address_line')) {
            Schema::table('profile_addresses', function (Blueprint $table): void {
                $table->dropColumn('address_line');
            });
        }

        if (Schema::hasColumn('profile_addresses', 'address_scope')) {
            Schema::table('profile_addresses', function (Blueprint $table): void {
                $table->dropColumn('address_scope');
            });
        }
    }
};
