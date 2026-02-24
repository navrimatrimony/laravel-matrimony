<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('profile_addresses', function (Blueprint $table) {
        $table->foreignId('village_id')
              ->nullable()
              ->after('village')
              ->constrained('villages')
              ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('profile_addresses', function (Blueprint $table) {
        $table->dropForeign(['village_id']);
        $table->dropColumn('village_id');
    });
}
};
