<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['religions', 'castes', 'sub_castes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('label_en')->nullable();
                $table->string('label_mr')->nullable();
            });
        }

        DB::table('religions')->whereNull('label_en')->update(['label_en' => DB::raw('`label`')]);
        DB::table('castes')->whereNull('label_en')->update(['label_en' => DB::raw('`label`')]);
        DB::table('sub_castes')->whereNull('label_en')->update(['label_en' => DB::raw('`label`')]);

        Schema::create('religion_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('religion_id')->constrained('religions')->cascadeOnDelete();
            $table->string('alias');
            $table->string('alias_type', 16);
            $table->string('normalized_alias', 512)->index();
            $table->timestamps();
            $table->index(['religion_id', 'normalized_alias']);
        });

        Schema::create('caste_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caste_id')->constrained('castes')->cascadeOnDelete();
            $table->string('alias');
            $table->string('alias_type', 16);
            $table->string('normalized_alias', 512)->index();
            $table->timestamps();
            $table->index(['caste_id', 'normalized_alias']);
        });

        Schema::create('sub_caste_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_caste_id')->constrained('sub_castes')->cascadeOnDelete();
            $table->string('alias');
            $table->string('alias_type', 16);
            $table->string('normalized_alias', 512)->index();
            $table->timestamps();
            $table->index(['sub_caste_id', 'normalized_alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_caste_aliases');
        Schema::dropIfExists('caste_aliases');
        Schema::dropIfExists('religion_aliases');

        foreach (['religions', 'castes', 'sub_castes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['label_en', 'label_mr']);
            });
        }
    }
};
