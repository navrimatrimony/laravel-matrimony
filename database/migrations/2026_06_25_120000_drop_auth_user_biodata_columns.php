<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'marital_status')) {
                $table->dropColumn('marital_status');
            }

            if (Schema::hasColumn('users', 'dob')) {
                $table->dropColumn('dob');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable()->after('name');
            }

            if (! Schema::hasColumn('users', 'marital_status')) {
                $table->string('marital_status', 64)->nullable()->after('dob');
            }
        });
    }
};
