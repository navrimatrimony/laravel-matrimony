<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Districts: URL-safe slug per state, and prevent duplicate English names within a state.
     */
    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->after('name');
        });

        $rows = DB::table('districts')->orderBy('id')->get(['id', 'state_id', 'name']);

        foreach ($rows as $row) {
            $base = Str::slug((string) $row->name);
            if ($base === '') {
                $base = 'district-'.$row->id;
            }
            $slug = $base;
            $n = 2;
            while (DB::table('districts')
                ->where('state_id', $row->state_id)
                ->where('slug', $slug)
                ->where('id', '<>', $row->id)
                ->exists()) {
                $slug = $base.'-'.$n;
                $n++;
            }
            DB::table('districts')->where('id', $row->id)->update(['slug' => $slug]);
        }

        Schema::table('districts', function (Blueprint $table) {
            $table->string('slug', 191)->nullable(false)->change();
        });

        Schema::table('districts', function (Blueprint $table) {
            $table->unique(['state_id', 'name'], 'districts_state_id_name_unique');
            $table->unique(['state_id', 'slug'], 'districts_state_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->dropUnique('districts_state_id_slug_unique');
            $table->dropUnique('districts_state_id_name_unique');
        });

        Schema::table('districts', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
