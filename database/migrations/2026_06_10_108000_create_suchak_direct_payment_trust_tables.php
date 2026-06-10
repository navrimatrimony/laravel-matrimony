<?php

use App\Models\SuchakDirectPaymentEvidence;
use App\Models\SuchakDispute;
use App\Models\SuchakPaymentFeatureFreeze;
use App\Models\SuchakPayoutHold;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_disputes', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_context_id')->nullable()->after('representation_id');
            $table->unsignedBigInteger('payment_context_id')->nullable()->after('customer_context_id');
            $table->string('risk_source', 64)->default(SuchakDispute::RISK_SOURCE_ADMIN_CASE)->after('priority');

            $table->index('customer_context_id', 'sk_disputes_customer_context_idx');
            $table->index('payment_context_id', 'sk_disputes_payment_context_idx');
            $table->index('risk_source', 'sk_disputes_risk_source_idx');

            $table->foreign('customer_context_id', 'sk_disputes_customer_context_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_disputes_payment_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
        });

        Schema::create('suchak_direct_payment_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_dispute_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('payment_context_id')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->string('evidence_type', 64)->default(SuchakDirectPaymentEvidence::TYPE_OTHER);
            $table->string('evidence_reference', 500)->nullable();
            $table->text('evidence_note');
            $table->timestamp('submitted_at');
            $table->timestamp('created_at')->nullable();

            $table->index('suchak_dispute_id', 'sk_direct_pay_ev_dispute_idx');
            $table->index('suchak_account_id', 'sk_direct_pay_ev_account_idx');
            $table->index('customer_context_id', 'sk_direct_pay_ev_customer_idx');
            $table->index('payment_context_id', 'sk_direct_pay_ev_context_idx');
            $table->index('submitted_by_user_id', 'sk_direct_pay_ev_user_idx');
            $table->index('evidence_type', 'sk_direct_pay_ev_type_idx');
            $table->index('submitted_at', 'sk_direct_pay_ev_submitted_idx');

            $table->foreign('suchak_dispute_id', 'sk_direct_pay_ev_dispute_fk')->references('id')->on('suchak_disputes')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_direct_pay_ev_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_direct_pay_ev_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_direct_pay_ev_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('submitted_by_user_id', 'sk_direct_pay_ev_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_payment_feature_freezes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_dispute_id')->nullable();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('payment_context_id')->nullable();
            $table->string('freeze_scope', 64)->default(SuchakPaymentFeatureFreeze::SCOPE_DIRECT_COLLECTION);
            $table->string('freeze_status', 32)->default(SuchakPaymentFeatureFreeze::STATUS_ACTIVE);
            $table->text('freeze_reason');
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->unsignedBigInteger('released_by_admin_user_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index('suchak_dispute_id', 'sk_pay_freezes_dispute_idx');
            $table->index('suchak_account_id', 'sk_pay_freezes_account_idx');
            $table->index('customer_context_id', 'sk_pay_freezes_customer_idx');
            $table->index('payment_context_id', 'sk_pay_freezes_context_idx');
            $table->index('freeze_scope', 'sk_pay_freezes_scope_idx');
            $table->index('freeze_status', 'sk_pay_freezes_status_idx');
            $table->index('created_by_admin_user_id', 'sk_pay_freezes_admin_idx');
            $table->index([
                'suchak_account_id',
                'customer_context_id',
                'freeze_status',
            ], 'sk_pay_freezes_account_customer_status_idx');

            $table->foreign('suchak_dispute_id', 'sk_pay_freezes_dispute_fk')->references('id')->on('suchak_disputes')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_pay_freezes_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_pay_freezes_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_pay_freezes_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('created_by_admin_user_id', 'sk_pay_freezes_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by_admin_user_id', 'sk_pay_freezes_released_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_payout_holds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_dispute_id')->nullable();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('payment_context_id')->nullable();
            $table->string('hold_scope', 64)->default(SuchakPayoutHold::SCOPE_DIRECT_PAYMENT_RISK);
            $table->string('hold_status', 32)->default(SuchakPayoutHold::STATUS_ACTIVE);
            $table->text('hold_reason');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index('suchak_dispute_id', 'sk_payout_holds_dispute_idx');
            $table->index('suchak_account_id', 'sk_payout_holds_account_idx');
            $table->index('customer_context_id', 'sk_payout_holds_customer_idx');
            $table->index('payment_context_id', 'sk_payout_holds_context_idx');
            $table->index('hold_scope', 'sk_payout_holds_scope_idx');
            $table->index('hold_status', 'sk_payout_holds_status_idx');
            $table->index('created_by_user_id', 'sk_payout_holds_created_by_idx');
            $table->index([
                'suchak_account_id',
                'customer_context_id',
                'hold_status',
            ], 'sk_payout_holds_account_customer_status_idx');

            $table->foreign('suchak_dispute_id', 'sk_payout_holds_dispute_fk')->references('id')->on('suchak_disputes')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_payout_holds_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_payout_holds_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_payout_holds_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sk_payout_holds_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by_user_id', 'sk_payout_holds_released_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_payout_holds');
        Schema::dropIfExists('suchak_payment_feature_freezes');
        Schema::dropIfExists('suchak_direct_payment_evidence');

        Schema::table('suchak_disputes', function (Blueprint $table): void {
            $table->dropForeign('sk_disputes_payment_context_fk');
            $table->dropForeign('sk_disputes_customer_context_fk');
            $table->dropIndex('sk_disputes_payment_context_idx');
            $table->dropIndex('sk_disputes_customer_context_idx');
            $table->dropIndex('sk_disputes_risk_source_idx');
            $table->dropColumn(['payment_context_id', 'customer_context_id', 'risk_source']);
        });
    }
};
