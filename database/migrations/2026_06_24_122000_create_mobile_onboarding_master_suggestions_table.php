<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_onboarding_master_suggestions')) {
            return;
        }

        Schema::create('mobile_onboarding_master_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('label', 160);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('working_with_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('suggested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('category_id');
            $table->index('working_with_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_onboarding_master_suggestions');
    }
};
