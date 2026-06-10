<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_plans', 'billing_period_days')) {
                $table->unsignedSmallInteger('billing_period_days')->default(30)->after('currency');
            }
        });

        Schema::create('suchak_plan_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('suchak_plan_id');
            $table->unsignedBigInteger('suchak_subscription_id')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id');
            $table->string('txnid', 80);
            $table->string('gateway_txnid', 120)->nullable();
            $table->string('plan_name', 120);
            $table->string('plan_slug', 80);
            $table->unsignedSmallInteger('billing_period_days');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('payment_status', 32)->default('pending');
            $table->string('gateway', 32)->default('payu');
            $table->string('source', 32)->default('checkout');
            $table->string('product_info', 120);
            $table->string('gateway_status', 32)->nullable();
            $table->string('gateway_mode', 64)->nullable();
            $table->string('response_hash', 128)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique('txnid', 'suchak_plan_payments_txnid_unique');
            $table->index('suchak_account_id', 'suchak_plan_payments_account_idx');
            $table->index('suchak_plan_id', 'suchak_plan_payments_plan_idx');
            $table->index('suchak_subscription_id', 'suchak_plan_payments_subscription_idx');
            $table->index('initiated_by_user_id', 'suchak_plan_payments_actor_idx');
            $table->index(['suchak_account_id', 'payment_status', 'created_at'], 'suchak_plan_payments_history_idx');

            $table->foreign('suchak_account_id', 'suchak_plan_payments_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('suchak_plan_id', 'suchak_plan_payments_plan_fk')->references('id')->on('suchak_plans')->restrictOnDelete();
            $table->foreign('suchak_subscription_id', 'suchak_plan_payments_sub_fk')->references('id')->on('suchak_subscriptions')->restrictOnDelete();
            $table->foreign('initiated_by_user_id', 'suchak_plan_payments_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_plan_invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_plan_payment_id');
            $table->string('invoice_number', 80);
            $table->string('fy_label', 16);
            $table->unsignedInteger('sequence_no');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('suchak_plan_payment_id', 'suchak_plan_invoices_payment_unique');
            $table->unique('invoice_number', 'suchak_plan_invoices_number_unique');
            $table->unique(['fy_label', 'sequence_no'], 'suchak_plan_invoices_fy_seq_unique');
            $table->index('fy_label', 'suchak_plan_invoices_fy_idx');

            $table->foreign('suchak_plan_payment_id', 'suchak_plan_invoices_payment_fk')->references('id')->on('suchak_plan_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_plan_invoices');
        Schema::dropIfExists('suchak_plan_payments');

        Schema::table('suchak_plans', function (Blueprint $table): void {
            if (Schema::hasColumn('suchak_plans', 'billing_period_days')) {
                $table->dropColumn('billing_period_days');
            }
        });
    }
};
