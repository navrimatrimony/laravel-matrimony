<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            $table->longText('last_parse_input_text')->nullable()->after('parsed_json');
        });
    }

    public function down(): void
    {
        Schema::table('biodata_intakes', function (Blueprint $table) {
            $table->dropColumn('last_parse_input_text');
        });
    }
};
