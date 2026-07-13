<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            $table->json('field_resolution_json')->nullable()->after('last_parse_input_text');
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            $table->dropColumn('field_resolution_json');
        });
    }
};
