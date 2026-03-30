<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('biodata_intakes', 'parsed_at')) {
                $table->timestamp('parsed_at')->nullable()->after('parse_status');
            }
        });

        // Best-effort for rows parsed before this column existed (approximate).
        DB::table('biodata_intakes')
            ->where('parse_status', 'parsed')
            ->whereNull('parsed_at')
            ->update(['parsed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (Schema::hasColumn('biodata_intakes', 'parsed_at')) {
                $table->dropColumn('parsed_at');
            }
        });
    }
};
