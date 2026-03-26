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
        Schema::create('showcase_admin_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id');
            $table->unsignedBigInteger('showcase_profile_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('action_type', 64);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['showcase_profile_id', 'created_at'], 'saa_showcase_created_at_idx');
            $table->index(['conversation_id', 'created_at'], 'saa_conversation_created_at_idx');

            $table->foreign('admin_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('showcase_profile_id')->references('id')->on('matrimony_profiles')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('showcase_admin_actions');
    }
};
