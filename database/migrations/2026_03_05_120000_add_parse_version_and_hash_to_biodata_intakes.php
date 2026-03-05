<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('biodata_intakes', 'parser_version')) {
                $table->string('parser_version', 64)->nullable()->after('snapshot_schema_version');
            }
            if (! Schema::hasColumn('biodata_intakes', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->after('parser_version');
                $table->index('content_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (Schema::hasColumn('biodata_intakes', 'content_hash')) {
                $table->dropIndex(['content_hash']);
                $table->dropColumn('content_hash');
            }
            if (Schema::hasColumn('biodata_intakes', 'parser_version')) {
                $table->dropColumn('parser_version');
            }
        });
    }
};

