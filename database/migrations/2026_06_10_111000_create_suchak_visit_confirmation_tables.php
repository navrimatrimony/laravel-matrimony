<?php

use App\Models\SuchakPolicy;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pipeline_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('representation_id');
            $table->unsignedBigInteger('target_matrimony_profile_id');
            $table->unsignedBigInteger('requesting_matrimony_profile_id');
            $table->unsignedBigInteger('payment_context_id')->nullable();
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('platform_payout_id')->nullable();
            $table->unsignedBigInteger('dispute_id')->nullable();
            $table->unsignedBigInteger('payout_hold_id')->nullable();
            $table->string('visit_status', 40)->default(SuchakVisitConfirmation::STATUS_SCHEDULED);
            $table->string('confirmation_policy_mode', 40)->default(SuchakVisitConfirmation::POLICY_USER_AND_ADMIN);
            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedBigInteger('scheduled_by_user_id');
            $table->timestamp('scheduled_at');
            $table->text('schedule_note')->nullable();
            $table->string('suchak_completion_status', 40)->default(SuchakVisitConfirmation::COMPLETION_PENDING);
            $table->unsignedBigInteger('suchak_completed_by_user_id')->nullable();
            $table->timestamp('suchak_completed_at')->nullable();
            $table->text('suchak_completion_note')->nullable();
            $table->string('user_confirmation_status', 40)->default(SuchakVisitConfirmation::CONFIRMATION_PENDING);
            $table->unsignedBigInteger('user_confirmed_by_user_id')->nullable();
            $table->timestamp('user_confirmed_at')->nullable();
            $table->text('user_confirmation_note')->nullable();
            $table->string('admin_confirmation_status', 40)->default(SuchakVisitConfirmation::CONFIRMATION_PENDING);
            $table->unsignedBigInteger('admin_confirmed_by_user_id')->nullable();
            $table->timestamp('admin_confirmed_at')->nullable();
            $table->text('admin_confirmation_note')->nullable();
            $table->string('refund_review_status', 40)->default(SuchakVisitConfirmation::REFUND_NOT_REQUESTED);
            $table->text('refund_review_note')->nullable();
            $table->timestamp('payout_qualified_at')->nullable();
            $table->timestamps();

            $table->unique('pipeline_id', 'sk_visit_confirmations_pipeline_unique');
            $table->index('suchak_account_id', 'sk_visit_confirmations_account_idx');
            $table->index('request_id', 'sk_visit_confirmations_request_idx');
            $table->index('representation_id', 'sk_visit_confirmations_rep_idx');
            $table->index('payment_context_id', 'sk_visit_confirmations_pay_context_idx');
            $table->index('customer_context_id', 'sk_visit_confirmations_customer_idx');
            $table->index('platform_payout_id', 'sk_visit_confirmations_payout_idx');
            $table->index('dispute_id', 'sk_visit_confirmations_dispute_idx');
            $table->index('payout_hold_id', 'sk_visit_confirmations_hold_idx');
            $table->index('visit_status', 'sk_visit_confirmations_status_idx');
            $table->index('confirmation_policy_mode', 'sk_visit_confirmations_policy_idx');
            $table->index('scheduled_for', 'sk_visit_confirmations_scheduled_for_idx');

            $table->foreign('pipeline_id', 'sk_visit_confirmations_pipeline_fk')->references('id')->on('suchak_pipelines')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_visit_confirmations_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('request_id', 'sk_visit_confirmations_request_fk')->references('id')->on('suchak_profile_requests')->restrictOnDelete();
            $table->foreign('representation_id', 'sk_visit_confirmations_rep_fk')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
            $table->foreign('target_matrimony_profile_id', 'sk_visit_confirmations_target_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('requesting_matrimony_profile_id', 'sk_visit_confirmations_requesting_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_visit_confirmations_payment_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_visit_confirmations_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('platform_payout_id', 'sk_visit_confirmations_payout_fk')->references('id')->on('suchak_platform_payouts')->restrictOnDelete();
            $table->foreign('dispute_id', 'sk_visit_confirmations_dispute_fk')->references('id')->on('suchak_disputes')->restrictOnDelete();
            $table->foreign('payout_hold_id', 'sk_visit_confirmations_hold_fk')->references('id')->on('suchak_payout_holds')->restrictOnDelete();
            $table->foreign('scheduled_by_user_id', 'sk_visit_confirmations_scheduled_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('suchak_completed_by_user_id', 'sk_visit_confirmations_completed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('user_confirmed_by_user_id', 'sk_visit_confirmations_user_confirmed_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_confirmed_by_user_id', 'sk_visit_confirmations_admin_confirmed_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_visit_confirmation_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('visit_confirmation_id');
            $table->unsignedBigInteger('pipeline_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('event_note')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('visit_confirmation_id', 'sk_visit_events_visit_idx');
            $table->index('pipeline_id', 'sk_visit_events_pipeline_idx');
            $table->index('suchak_account_id', 'sk_visit_events_account_idx');
            $table->index('event_type', 'sk_visit_events_type_idx');
            $table->index('actor_user_id', 'sk_visit_events_actor_idx');
            $table->index('occurred_at', 'sk_visit_events_time_idx');

            $table->foreign('visit_confirmation_id', 'sk_visit_events_visit_fk')->references('id')->on('suchak_visit_confirmations')->restrictOnDelete();
            $table->foreign('pipeline_id', 'sk_visit_events_pipeline_fk')->references('id')->on('suchak_pipelines')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_visit_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_visit_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE,
                'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Confirmation policy required before platform visit payouts can be qualified.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE)
            ->delete();

        Schema::dropIfExists('suchak_visit_confirmation_events');
        Schema::dropIfExists('suchak_visit_confirmations');
    }
};
