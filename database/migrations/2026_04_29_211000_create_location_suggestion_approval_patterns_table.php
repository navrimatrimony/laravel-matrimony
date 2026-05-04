<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned mappings from admin-approved open-place suggestions (normalized key → canonical targets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_suggestion_approval_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_input', 255);
            $table->foreignId('resolved_city_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->unsignedBigInteger('resolved_location_id')->nullable();
            $table->string('suggested_type', 32)->nullable();
            $table->unsignedBigInteger('suggested_parent_id')->nullable();
            $table->unsignedInteger('confirmation_count')->default(1);
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique('normalized_input');
            $table->index(['confirmation_count', 'updated_at'], 'lsap_conf_cnt_upd_idx');
        });

        if (Schema::hasTable('addresses')) {
            Schema::table('location_suggestion_approval_patterns', function (Blueprint $table) {
                $table->foreign('resolved_location_id', 'lsap_resolved_loc_fk')
                    ->references('id')
                    ->on('addresses')
                    ->nullOnDelete();
                $table->foreign('suggested_parent_id', 'lsap_parent_loc_fk')
                    ->references('id')
                    ->on('addresses')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_suggestion_approval_patterns');
    }
};
