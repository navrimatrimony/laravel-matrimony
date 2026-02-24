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
    Schema::table('matrimony_profiles', function (Blueprint $table) {
        $table->foreignId('religion_id')
              ->nullable()
              ->after('religion')
              ->constrained('religions')
              ->nullOnDelete();

        $table->foreignId('caste_id')
              ->nullable()
              ->after('caste')
              ->constrained('castes')
              ->nullOnDelete();

        $table->foreignId('sub_caste_id')
              ->nullable()
              ->after('sub_caste')
              ->constrained('sub_castes')
              ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('matrimony_profiles', function (Blueprint $table) {
        $table->dropForeign(['religion_id']);
        $table->dropForeign(['caste_id']);
        $table->dropForeign(['sub_caste_id']);

        $table->dropColumn([
            'religion_id',
            'caste_id',
            'sub_caste_id'
        ]);
    });
}
};
