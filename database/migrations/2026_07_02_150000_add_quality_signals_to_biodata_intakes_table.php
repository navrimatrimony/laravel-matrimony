<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table): void {
            if (! Schema::hasColumn('biodata_intakes', 'quality_summary_json')) {
                $table->json('quality_summary_json')->nullable();
            }
            if (! Schema::hasColumn('biodata_intakes', 'failure_codes_json')) {
                $table->json('failure_codes_json')->nullable();
            }
            if (! Schema::hasColumn('biodata_intakes', 'field_confidence_json')) {
                $table->json('field_confidence_json')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table): void {
            foreach ([
                'field_confidence_json',
                'failure_codes_json',
                'quality_summary_json',
            ] as $column) {
                if (Schema::hasColumn('biodata_intakes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
