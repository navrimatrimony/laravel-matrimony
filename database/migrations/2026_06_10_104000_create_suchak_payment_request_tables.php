<?php

use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('customer_agreement_id');
            $table->unsignedBigInteger('payment_context_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('request_token_hash', 64);
            $table->string('payment_status', 32)->default(SuchakPaymentRequest::STATUS_DRAFT);
            $table->string('payment_detail_visibility_policy', 64)->default(SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY);
            $table->string('request_title', 160);
            $table->text('request_note')->nullable();
            $table->decimal('amount_due', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('collector_disclosure');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique('request_token_hash', 'sk_pay_requests_token_hash_unique');
            $table->index('suchak_account_id', 'sk_pay_requests_account_idx');
            $table->index('customer_context_id', 'sk_pay_requests_customer_idx');
            $table->index('service_package_id', 'sk_pay_requests_package_idx');
            $table->index('customer_agreement_id', 'sk_pay_requests_agreement_idx');
            $table->index('payment_context_id', 'sk_pay_requests_context_idx');
            $table->index('requested_by_user_id', 'sk_pay_requests_requested_by_idx');
            $table->index('payment_status', 'sk_pay_requests_status_idx');
            $table->index('expires_at', 'sk_pay_requests_expires_idx');
            $table->index('cancelled_by_user_id', 'sk_pay_requests_cancelled_by_idx');
            $table->index('created_at', 'sk_pay_requests_created_idx');
            $table->index([
                'suchak_account_id',
                'customer_context_id',
                'payment_status',
            ], 'sk_pay_requests_account_customer_status_idx');

            $table->foreign('suchak_account_id', 'sk_pay_requests_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_pay_requests_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('service_package_id', 'sk_pay_requests_package_fk')->references('id')->on('suchak_service_packages')->restrictOnDelete();
            $table->foreign('customer_agreement_id', 'sk_pay_requests_agreement_fk')->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_pay_requests_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'sk_pay_requests_requested_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by_user_id', 'sk_pay_requests_cancelled_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_payment_request_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_request_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('event_note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_request_id', 'sk_pay_req_events_request_idx');
            $table->index('suchak_account_id', 'sk_pay_req_events_account_idx');
            $table->index('event_type', 'sk_pay_req_events_type_idx');
            $table->index('actor_type', 'sk_pay_req_events_actor_type_idx');
            $table->index('actor_user_id', 'sk_pay_req_events_actor_idx');
            $table->index('occurred_at', 'sk_pay_req_events_occurred_idx');
            $table->index([
                'suchak_account_id',
                'event_type',
                'occurred_at',
            ], 'sk_pay_req_events_account_type_idx');

            $table->foreign('payment_request_id', 'sk_pay_req_events_request_fk')->references('id')->on('suchak_payment_requests')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_pay_req_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_pay_req_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        $now = now();

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => 'suchak_payment_detail_visibility_policy',
                'policy_value' => SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Suchak payment request direct payment detail visibility policy.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', 'suchak_payment_detail_visibility_policy')
            ->delete();

        Schema::dropIfExists('suchak_payment_request_events');
        Schema::dropIfExists('suchak_payment_requests');
    }
};
