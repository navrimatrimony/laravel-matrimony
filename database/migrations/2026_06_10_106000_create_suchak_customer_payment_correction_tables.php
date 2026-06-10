<?php

use App\Models\SuchakCustomerOverdueServiceAction;
use App\Models\SuchakCustomerPaymentCorrection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_customer_payment_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_payment_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('payment_request_id');
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->string('correction_type', 32);
            $table->string('correction_status', 32);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->text('reason');
            $table->string('document_number', 96)->nullable();
            $table->string('fy_label', 16)->nullable();
            $table->unsignedInteger('sequence_no')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('status_note')->nullable();
            $table->timestamps();

            $table->unique('document_number', 'sk_cust_pay_corr_doc_unique');
            $table->unique([
                'correction_type',
                'fy_label',
                'sequence_no',
            ], 'sk_cust_pay_corr_type_fy_seq_unique');
            $table->index('customer_payment_id', 'sk_cust_pay_corr_payment_idx');
            $table->index('suchak_account_id', 'sk_cust_pay_corr_account_idx');
            $table->index('customer_context_id', 'sk_cust_pay_corr_customer_idx');
            $table->index('payment_request_id', 'sk_cust_pay_corr_request_idx');
            $table->index('ledger_entry_id', 'sk_cust_pay_corr_ledger_idx');
            $table->index('correction_type', 'sk_cust_pay_corr_type_idx');
            $table->index('correction_status', 'sk_cust_pay_corr_status_idx');
            $table->index('requested_by_user_id', 'sk_cust_pay_corr_requested_by_idx');
            $table->index('approved_by_user_id', 'sk_cust_pay_corr_approved_by_idx');
            $table->index('paid_by_user_id', 'sk_cust_pay_corr_paid_by_idx');
            $table->index('posted_by_user_id', 'sk_cust_pay_corr_posted_by_idx');
            $table->index('cancelled_by_user_id', 'sk_cust_pay_corr_cancelled_by_idx');
            $table->index('created_at', 'sk_cust_pay_corr_created_idx');
            $table->index([
                'suchak_account_id',
                'correction_type',
                'correction_status',
            ], 'sk_cust_pay_corr_account_type_status_idx');

            $table->foreign('customer_payment_id', 'sk_cust_pay_corr_payment_fk')->references('id')->on('suchak_customer_payments')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_cust_pay_corr_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_cust_pay_corr_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_request_id', 'sk_cust_pay_corr_request_fk')->references('id')->on('suchak_payment_requests')->restrictOnDelete();
            $table->foreign('ledger_entry_id', 'sk_cust_pay_corr_ledger_fk')->references('id')->on('suchak_ledger_entries')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'sk_cust_pay_corr_requested_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by_user_id', 'sk_cust_pay_corr_approved_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('paid_by_user_id', 'sk_cust_pay_corr_paid_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by_user_id', 'sk_cust_pay_corr_posted_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by_user_id', 'sk_cust_pay_corr_cancelled_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_payment_correction_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_correction_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('event_note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_correction_id', 'sk_cust_pay_corr_events_corr_idx');
            $table->index('suchak_account_id', 'sk_cust_pay_corr_events_account_idx');
            $table->index('event_type', 'sk_cust_pay_corr_events_type_idx');
            $table->index('actor_type', 'sk_cust_pay_corr_events_actor_type_idx');
            $table->index('actor_user_id', 'sk_cust_pay_corr_events_actor_idx');
            $table->index('occurred_at', 'sk_cust_pay_corr_events_occurred_idx');

            $table->foreign('payment_correction_id', 'sk_cust_pay_corr_events_corr_fk')->references('id')->on('suchak_customer_payment_corrections')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_cust_pay_corr_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_cust_pay_corr_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_overdue_service_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_payment_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('payment_request_id');
            $table->string('action_type', 64)->default(SuchakCustomerOverdueServiceAction::TYPE_PAYMENT_FOLLOWUP);
            $table->string('action_status', 32)->default(SuchakCustomerOverdueServiceAction::STATUS_OPEN);
            $table->string('action_policy', 64)->default(SuchakCustomerOverdueServiceAction::POLICY_SUCHAK_SERVICE_ONLY);
            $table->decimal('due_amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->text('reason');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index('customer_payment_id', 'sk_cust_overdue_payment_idx');
            $table->index('suchak_account_id', 'sk_cust_overdue_account_idx');
            $table->index('customer_context_id', 'sk_cust_overdue_customer_idx');
            $table->index('payment_request_id', 'sk_cust_overdue_request_idx');
            $table->index('action_type', 'sk_cust_overdue_type_idx');
            $table->index('action_status', 'sk_cust_overdue_status_idx');
            $table->index('action_policy', 'sk_cust_overdue_policy_idx');
            $table->index('created_by_user_id', 'sk_cust_overdue_created_by_idx');
            $table->index('resolved_by_user_id', 'sk_cust_overdue_resolved_by_idx');
            $table->index([
                'suchak_account_id',
                'customer_context_id',
                'action_status',
            ], 'sk_cust_overdue_account_customer_status_idx');

            $table->foreign('customer_payment_id', 'sk_cust_overdue_payment_fk')->references('id')->on('suchak_customer_payments')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_cust_overdue_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_cust_overdue_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_request_id', 'sk_cust_overdue_request_fk')->references('id')->on('suchak_payment_requests')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sk_cust_overdue_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('resolved_by_user_id', 'sk_cust_overdue_resolved_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_customer_overdue_service_actions');
        Schema::dropIfExists('suchak_customer_payment_correction_events');
        Schema::dropIfExists('suchak_customer_payment_corrections');
    }
};
