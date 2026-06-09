<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_consents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('representation_id');
            $table->string('consent_status')->default('requested');
            $table->string('consent_type')->default('one_year');
            $table->text('consent_text_snapshot');
            $table->string('consent_template_version')->default('v1');
            $table->string('consent_given_by_name')->nullable();
            $table->string('relationship_to_candidate')->nullable();
            $table->string('consent_mobile_number')->nullable();
            $table->string('token_hash', 64);
            $table->timestamp('token_expires_at');
            $table->string('otp_hash')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->timestamp('last_otp_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('otp_verified_at')->nullable();
            $table->string('consent_channel')->default('whatsapp_deep_link');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->unique('token_hash');
            $table->index('token_expires_at');
            $table->index('suchak_account_id');
            $table->index('matrimony_profile_id');
            $table->index('representation_id');
            $table->index('consent_status');
            $table->index('consent_type');
            $table->index('consent_channel');
            $table->index(['suchak_account_id', 'matrimony_profile_id'], 'suchak_consents_account_profile_idx');
            $table->index(['suchak_account_id', 'matrimony_profile_id', 'representation_id'], 'suchak_consents_account_profile_repr_idx');
            $table->index('created_at');

            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('representation_id')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
        });

        Schema::create('suchak_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('consent_id');
            $table->string('event_type');
            $table->text('event_note')->nullable();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('consent_id');
            $table->index('event_type');
            $table->index('actor_type');
            $table->index('actor_id');
            $table->index('created_at');

            $table->foreign('consent_id')->references('id')->on('suchak_consents')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_consent_events');
        Schema::dropIfExists('suchak_consents');
    }
};
