<?php

use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_customer_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('customer_agreement_id');
            $table->unsignedBigInteger('payment_context_id');
            $table->unsignedBigInteger('payment_request_id');
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->string('collection_channel', 32)->default(SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT);
            $table->string('payment_mode', 32);
            $table->string('payment_status', 32)->default(SuchakCustomerPayment::STATUS_PENDING);
            $table->decimal('amount_due', 12, 2);
            $table->decimal('amount_received', 12, 2)->default(0);
            $table->decimal('balance_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->timestamp('payment_received_at')->nullable();
            $table->string('payment_reference', 160)->nullable();
            $table->string('proof_status', 32)->default(SuchakCustomerPayment::PROOF_NOT_REQUIRED);
            $table->string('proof_document_path', 500)->nullable();
            $table->text('proof_note')->nullable();
            $table->unsignedBigInteger('proof_verified_by_user_id')->nullable();
            $table->timestamp('proof_verified_at')->nullable();
            $table->text('proof_rejection_reason')->nullable();
            $table->text('collection_note')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_cust_pay_account_idx');
            $table->index('customer_context_id', 'sk_cust_pay_customer_idx');
            $table->index('service_package_id', 'sk_cust_pay_package_idx');
            $table->index('customer_agreement_id', 'sk_cust_pay_agreement_idx');
            $table->index('payment_context_id', 'sk_cust_pay_context_idx');
            $table->index('payment_request_id', 'sk_cust_pay_request_idx');
            $table->index('ledger_entry_id', 'sk_cust_pay_ledger_idx');
            $table->index('recorded_by_user_id', 'sk_cust_pay_recorded_by_idx');
            $table->index('payment_mode', 'sk_cust_pay_mode_idx');
            $table->index('payment_status', 'sk_cust_pay_status_idx');
            $table->index('proof_status', 'sk_cust_pay_proof_status_idx');
            $table->index('payment_received_at', 'sk_cust_pay_received_idx');
            $table->index('created_at', 'sk_cust_pay_created_idx');
            $table->index([
                'suchak_account_id',
                'customer_context_id',
                'payment_status',
            ], 'sk_cust_pay_account_customer_status_idx');

            $table->foreign('suchak_account_id', 'sk_cust_pay_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_cust_pay_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('service_package_id', 'sk_cust_pay_package_fk')->references('id')->on('suchak_service_packages')->restrictOnDelete();
            $table->foreign('customer_agreement_id', 'sk_cust_pay_agreement_fk')->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_cust_pay_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('payment_request_id', 'sk_cust_pay_request_fk')->references('id')->on('suchak_payment_requests')->restrictOnDelete();
            $table->foreign('ledger_entry_id', 'sk_cust_pay_ledger_fk')->references('id')->on('suchak_ledger_entries')->restrictOnDelete();
            $table->foreign('recorded_by_user_id', 'sk_cust_pay_recorded_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('proof_verified_by_user_id', 'sk_cust_pay_proof_verified_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_payment_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_payment_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->string('document_type', 32);
            $table->string('document_number', 96);
            $table->string('fy_label', 16);
            $table->unsignedInteger('sequence_no');
            $table->string('verification_code', 64)->nullable();
            $table->unsignedBigInteger('issued_by_user_id');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('document_number', 'sk_cust_pay_docs_number_unique');
            $table->unique('verification_code', 'sk_cust_pay_docs_verify_unique');
            $table->unique([
                'document_type',
                'fy_label',
                'sequence_no',
            ], 'sk_cust_pay_docs_type_fy_seq_unique');
            $table->index('customer_payment_id', 'sk_cust_pay_docs_payment_idx');
            $table->index('suchak_account_id', 'sk_cust_pay_docs_account_idx');
            $table->index('customer_context_id', 'sk_cust_pay_docs_customer_idx');
            $table->index('document_type', 'sk_cust_pay_docs_type_idx');
            $table->index('fy_label', 'sk_cust_pay_docs_fy_idx');
            $table->index('issued_by_user_id', 'sk_cust_pay_docs_issued_by_idx');
            $table->index('issued_at', 'sk_cust_pay_docs_issued_at_idx');

            $table->foreign('customer_payment_id', 'sk_cust_pay_docs_payment_fk')->references('id')->on('suchak_customer_payments')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_cust_pay_docs_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_cust_pay_docs_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('issued_by_user_id', 'sk_cust_pay_docs_issued_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_payment_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('event_note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index('customer_payment_id', 'sk_cust_pay_events_payment_idx');
            $table->index('suchak_account_id', 'sk_cust_pay_events_account_idx');
            $table->index('event_type', 'sk_cust_pay_events_type_idx');
            $table->index('actor_type', 'sk_cust_pay_events_actor_type_idx');
            $table->index('actor_user_id', 'sk_cust_pay_events_actor_idx');
            $table->index('occurred_at', 'sk_cust_pay_events_occurred_idx');
            $table->index([
                'suchak_account_id',
                'event_type',
                'occurred_at',
            ], 'sk_cust_pay_events_account_type_idx');

            $table->foreign('customer_payment_id', 'sk_cust_pay_events_payment_fk')->references('id')->on('suchak_customer_payments')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_cust_pay_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_cust_pay_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_customer_payment_events');
        Schema::dropIfExists('suchak_customer_payment_documents');
        Schema::dropIfExists('suchak_customer_payments');
    }
};
