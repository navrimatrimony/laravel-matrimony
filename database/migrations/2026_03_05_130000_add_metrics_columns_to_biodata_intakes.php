<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('biodata_intakes', 'parse_duration_ms')) {
                $table->integer('parse_duration_ms')->nullable()->after('parse_status');
            }
            if (! Schema::hasColumn('biodata_intakes', 'ai_calls_used')) {
                $table->integer('ai_calls_used')->nullable()->after('parse_duration_ms');
            }
            if (! Schema::hasColumn('biodata_intakes', 'fields_auto_filled_count')) {
                $table->integer('fields_auto_filled_count')->nullable()->after('ai_calls_used');
            }
            if (! Schema::hasColumn('biodata_intakes', 'fields_manually_edited_count')) {
                $table->integer('fields_manually_edited_count')->nullable()->after('fields_auto_filled_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            if (Schema::hasColumn('biodata_intakes', 'fields_manually_edited_count')) {
                $table->dropColumn('fields_manually_edited_count');
            }
            if (Schema::hasColumn('biodata_intakes', 'fields_auto_filled_count')) {
                $table->dropColumn('fields_auto_filled_count');
            }
            if (Schema::hasColumn('biodata_intakes', 'ai_calls_used')) {
                $table->dropColumn('ai_calls_used');
            }
            if (Schema::hasColumn('biodata_intakes', 'parse_duration_ms')) {
                $table->dropColumn('parse_duration_ms');
            }
        });
    }
};

