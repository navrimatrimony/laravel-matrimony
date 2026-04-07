<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_preferred_marital_statuses')) {
            return;
        }

        Schema::create('profile_preferred_marital_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('matrimony_profiles')->cascadeOnDelete();
            $table->foreignId('marital_status_id')->constrained('master_marital_statuses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['profile_id', 'marital_status_id'], 'ppf_marital_profile_ms_uniq');
        });

        if (Schema::hasTable('profile_preference_criteria') && Schema::hasColumn('profile_preference_criteria', 'preferred_marital_status_id')) {
            $rows = DB::table('profile_preference_criteria')
                ->whereNotNull('preferred_marital_status_id')
                ->select(['profile_id', 'preferred_marital_status_id'])
                ->get();
            $now = now();
            foreach ($rows as $row) {
                $pid = (int) $row->profile_id;
                $mid = (int) $row->preferred_marital_status_id;
                if ($pid < 1 || $mid < 1) {
                    continue;
                }
                DB::table('profile_preferred_marital_statuses')->insertOrIgnore([
                    'profile_id' => $pid,
                    'marital_status_id' => $mid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferred_marital_statuses');
    }
};
