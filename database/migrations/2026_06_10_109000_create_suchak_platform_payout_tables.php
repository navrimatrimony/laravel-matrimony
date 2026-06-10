<?php

use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutDetail;
use App\Models\SuchakPlatformPayoutEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_platform_payouts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('payment_context_id')->nullable();
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->string('platform_event_type', 64);
            $table->string('platform_event_key', 160);
            $table->string('payout_reason', 64);
            $table->string('qualification_source', 64)->default(SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT);
            $table->string('payout_status', 32)->default(SuchakPlatformPayout::STATUS_ON_HOLD);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->timestamp('liability_recognized_at');
            $table->unsignedBigInteger('qualified_by_user_id')->nullable();
            $table->text('qualification_note');
            $table->text('hold_reason')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('status_note')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_platform_payouts_account_idx');
            $table->index('customer_context_id', 'sk_platform_payouts_customer_idx');
            $table->index('payment_context_id', 'sk_platform_payouts_context_idx');
            $table->index('matrimony_profile_id', 'sk_platform_payouts_profile_idx');
            $table->index('payout_reason', 'sk_platform_payouts_reason_idx');
            $table->index('payout_status', 'sk_platform_payouts_status_idx');
            $table->index('qualification_source', 'sk_platform_payouts_source_idx');
            $table->unique([
                'suchak_account_id',
                'platform_event_type',
                'platform_event_key',
            ], 'sk_platform_payouts_event_unique');

            $table->foreign('suchak_account_id', 'sk_platform_payouts_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_platform_payouts_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_platform_payouts_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'sk_platform_payouts_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('qualified_by_user_id', 'sk_platform_payouts_qualified_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by_user_id', 'sk_platform_payouts_approved_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by_user_id', 'sk_platform_payouts_cancelled_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversed_by_user_id', 'sk_platform_payouts_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_platform_payout_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_payout_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('payout_method', 64);
            $table->string('payout_detail_reference', 500)->nullable();
            $table->string('beneficiary_name', 160)->nullable();
            $table->string('account_last_four', 4)->nullable();
            $table->string('ifsc_code', 16)->nullable();
            $table->string('upi_handle_masked', 160)->nullable();
            $table->string('verification_status', 32)->default(SuchakPlatformPayoutDetail::STATUS_PENDING);
            $table->text('verification_note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('platform_payout_id', 'sk_platform_payout_details_payout_idx');
            $table->index('suchak_account_id', 'sk_platform_payout_details_account_idx');
            $table->index('payout_method', 'sk_platform_payout_details_method_idx');
            $table->index('verification_status', 'sk_platform_payout_details_status_idx');

            $table->foreign('platform_payout_id', 'sk_platform_payout_details_payout_fk')->references('id')->on('suchak_platform_payouts')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_platform_payout_details_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sk_platform_payout_details_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('verified_by_user_id', 'sk_platform_payout_details_verified_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_platform_payout_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_payout_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32)->default(SuchakPlatformPayoutEvent::ACTOR_SYSTEM);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('event_note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('platform_payout_id', 'sk_platform_payout_events_payout_idx');
            $table->index('suchak_account_id', 'sk_platform_payout_events_account_idx');
            $table->index('event_type', 'sk_platform_payout_events_type_idx');
            $table->index('actor_user_id', 'sk_platform_payout_events_actor_idx');
            $table->index('occurred_at', 'sk_platform_payout_events_time_idx');

            $table->foreign('platform_payout_id', 'sk_platform_payout_events_payout_fk')->references('id')->on('suchak_platform_payouts')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_platform_payout_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_platform_payout_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_platform_payout_events');
        Schema::dropIfExists('suchak_platform_payout_details');
        Schema::dropIfExists('suchak_platform_payouts');
    }
};
