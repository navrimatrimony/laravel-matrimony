<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_profile_representations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('biodata_intake_id')->nullable();
            $table->string('representation_status')->default('pending');
            $table->string('representation_mode');
            $table->string('consent_status')->default('not_requested');
            $table->timestamp('first_uploaded_at')->nullable();
            $table->timestamp('first_identified_at')->nullable();
            $table->timestamp('first_verified_consent_at')->nullable();
            $table->timestamp('consent_verified_at')->nullable();
            $table->timestamp('consent_valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('candidate_deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['suchak_account_id', 'matrimony_profile_id'], 'suchak_repr_account_profile_unique');
            $table->index('suchak_account_id');
            $table->index('matrimony_profile_id');
            $table->index('biodata_intake_id');
            $table->index('representation_status');
            $table->index('representation_mode');
            $table->index('consent_status');
            $table->index('created_at');
            $table->index(['suchak_account_id', 'representation_status', 'created_at'], 'suchak_repr_account_status_created_idx');
            $table->index(['matrimony_profile_id', 'representation_status'], 'suchak_repr_profile_status_idx');

            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('biodata_intake_id')->references('id')->on('biodata_intakes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_profile_representations');
    }
};
