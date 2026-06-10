<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_profile_update_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('representation_id');
            $table->string('field_key');
            $table->text('old_value')->nullable();
            $table->text('suggested_value');
            $table->string('suggestion_status')->default('pending_candidate_confirmation');
            $table->string('otp_hash')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->timestamp('last_otp_sent_at')->nullable();
            $table->timestamp('candidate_verified_at')->nullable();
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'suchak_update_account_idx');
            $table->index('matrimony_profile_id', 'suchak_update_profile_idx');
            $table->index('representation_id', 'suchak_update_repr_idx');
            $table->index('field_key', 'suchak_update_field_idx');
            $table->index('suggestion_status', 'suchak_update_status_idx');
            $table->index('candidate_verified_at', 'suchak_update_candidate_verified_idx');
            $table->index('admin_reviewed_at', 'suchak_update_admin_reviewed_idx');
            $table->index('applied_at', 'suchak_update_applied_idx');
            $table->index(['suchak_account_id', 'matrimony_profile_id'], 'suchak_update_account_profile_idx');

            $table->foreign('suchak_account_id', 'suchak_update_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'suchak_update_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('representation_id', 'suchak_update_repr_fk')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_profile_update_suggestions');
    }
};
