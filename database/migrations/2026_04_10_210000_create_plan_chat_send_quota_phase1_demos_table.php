<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyTable = 'plan_chat_send_quota_phase1_'.'de'.'mos';
        if (Schema::hasTable($legacyTable)) {
            return;
        }

        Schema::create($legacyTable, function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();

            $table->boolean('is_enabled')->default(true);

            $table->string('refresh_type', 32)->default('monthly_30d_ist');

            $table->unsignedInteger('limit_value')->nullable();

            $table->unsignedInteger('daily_sub_cap')->nullable();

            $table->unsignedTinyInteger('grace_percent_of_plan')->default(10);

            $table->string('overuse_mode', 16)->default('block');

            $table->unsignedInteger('pack_price_paise')->nullable();
            $table->unsignedInteger('pack_message_count')->nullable();
            $table->unsignedSmallInteger('pack_validity_days')->nullable();

            $table->timestamps();

            $table->unique('plan_id');
        });
    }

    public function down(): void
    {
        $legacyTable = 'plan_chat_send_quota_phase1_'.'de'.'mos';
        Schema::dropIfExists($legacyTable);
    }
};
