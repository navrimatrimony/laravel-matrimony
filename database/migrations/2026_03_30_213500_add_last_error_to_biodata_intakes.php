<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('biodata_intakes', 'last_error')) {
                $table->string('last_error', 512)->nullable()->after('parse_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (Schema::hasColumn('biodata_intakes', 'last_error')) {
                $table->dropColumn('last_error');
            }
        });
    }
};
