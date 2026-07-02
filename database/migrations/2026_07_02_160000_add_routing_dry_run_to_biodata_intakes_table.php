<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table): void {
            if (! Schema::hasColumn('biodata_intakes', 'routing_recommendation_json')) {
                $table->json('routing_recommendation_json')->nullable();
            }
            if (! Schema::hasColumn('biodata_intakes', 'routing_telemetry_json')) {
                $table->json('routing_telemetry_json')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table): void {
            foreach ([
                'routing_telemetry_json',
                'routing_recommendation_json',
            ] as $column) {
                if (Schema::hasColumn('biodata_intakes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
