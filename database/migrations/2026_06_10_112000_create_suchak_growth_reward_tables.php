<?php

use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardEvent;
use App\Models\SuchakGrowthRewardRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_growth_attributions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('attributed_user_id')->nullable();
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('payment_context_id')->nullable();
            $table->string('attribution_source', 40);
            $table->string('attribution_policy', 40);
            $table->string('attribution_key', 160);
            $table->string('referral_code', 80)->nullable();
            $table->string('coupon_code', 80)->nullable();
            $table->string('attribution_status', 40)->default(SuchakGrowthAttribution::STATUS_ACTIVE);
            $table->string('fraud_status', 40)->default(SuchakGrowthAttribution::FRAUD_CLEAR);
            $table->json('fraud_flags')->nullable();
            $table->text('attribution_note');
            $table->unsignedBigInteger('attributed_by_admin_user_id');
            $table->timestamp('attributed_at');
            $table->timestamps();

            $table->unique([
                'suchak_account_id',
                'attribution_source',
                'attribution_key',
            ], 'sk_growth_attr_account_source_key_unique');
            $table->index('suchak_account_id', 'sk_growth_attr_account_idx');
            $table->index('attributed_user_id', 'sk_growth_attr_user_idx');
            $table->index('matrimony_profile_id', 'sk_growth_attr_profile_idx');
            $table->index('customer_context_id', 'sk_growth_attr_customer_idx');
            $table->index('payment_context_id', 'sk_growth_attr_payment_idx');
            $table->index('attribution_source', 'sk_growth_attr_source_idx');
            $table->index('attribution_policy', 'sk_growth_attr_policy_idx');
            $table->index('attribution_status', 'sk_growth_attr_status_idx');
            $table->index('fraud_status', 'sk_growth_attr_fraud_idx');

            $table->foreign('suchak_account_id', 'sk_growth_attr_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('attributed_user_id', 'sk_growth_attr_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'sk_growth_attr_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_growth_attr_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_growth_attr_payment_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('attributed_by_admin_user_id', 'sk_growth_attr_admin_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_growth_reward_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 96);
            $table->string('reward_trigger', 64)->default(SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED);
            $table->string('reward_type', 32);
            $table->string('attribution_policy', 40)->default(SuchakGrowthAttribution::POLICY_COUPON_PRIORITY);
            $table->decimal('reward_amount', 12, 2)->default(0);
            $table->string('reward_currency', 3)->default('INR');
            $table->decimal('credit_value', 12, 2)->default(0);
            $table->string('admin_action_key', 96)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->timestamps();

            $table->unique('rule_key', 'sk_growth_rules_key_unique');
            $table->index('reward_trigger', 'sk_growth_rules_trigger_idx');
            $table->index('reward_type', 'sk_growth_rules_type_idx');
            $table->index('attribution_policy', 'sk_growth_rules_policy_idx');
            $table->index('is_active', 'sk_growth_rules_active_idx');
            $table->index('starts_at', 'sk_growth_rules_starts_idx');
            $table->index('ends_at', 'sk_growth_rules_ends_idx');

            $table->foreign('created_by_admin_user_id', 'sk_growth_rules_admin_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_growth_rewards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('growth_attribution_id');
            $table->unsignedBigInteger('reward_rule_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('payment_context_id');
            $table->unsignedBigInteger('matrimony_profile_id')->nullable();
            $table->unsignedBigInteger('platform_payout_id')->nullable();
            $table->string('platform_event_key', 160);
            $table->string('reward_trigger', 64)->default(SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED);
            $table->string('reward_type', 32);
            $table->string('reward_status', 40)->default(SuchakGrowthReward::STATUS_QUALIFIED);
            $table->decimal('reward_amount', 12, 2)->default(0);
            $table->string('reward_currency', 3)->default('INR');
            $table->decimal('credit_value', 12, 2)->default(0);
            $table->string('admin_action_key', 96)->nullable();
            $table->string('qualification_source', 64)->default(SuchakGrowthReward::SOURCE_PLATFORM_CONFIRMED_PAYMENT);
            $table->string('fraud_status', 40)->default(SuchakGrowthAttribution::FRAUD_CLEAR);
            $table->json('fraud_flags')->nullable();
            $table->unsignedBigInteger('qualified_by_admin_user_id');
            $table->timestamp('qualified_at');
            $table->unsignedBigInteger('reversed_by_admin_user_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique('platform_event_key', 'sk_growth_rewards_event_key_unique');
            $table->unique([
                'growth_attribution_id',
                'payment_context_id',
                'reward_rule_id',
            ], 'sk_growth_rewards_attr_payment_rule_unique');
            $table->index('reward_rule_id', 'sk_growth_rewards_rule_idx');
            $table->index('suchak_account_id', 'sk_growth_rewards_account_idx');
            $table->index('customer_context_id', 'sk_growth_rewards_customer_idx');
            $table->index('payment_context_id', 'sk_growth_rewards_payment_idx');
            $table->index('matrimony_profile_id', 'sk_growth_rewards_profile_idx');
            $table->index('platform_payout_id', 'sk_growth_rewards_payout_idx');
            $table->index('reward_trigger', 'sk_growth_rewards_trigger_idx');
            $table->index('reward_type', 'sk_growth_rewards_type_idx');
            $table->index('reward_status', 'sk_growth_rewards_status_idx');
            $table->index('fraud_status', 'sk_growth_rewards_fraud_idx');
            $table->index('qualified_at', 'sk_growth_rewards_qualified_at_idx');

            $table->foreign('growth_attribution_id', 'sk_growth_rewards_attr_fk')->references('id')->on('suchak_growth_attributions')->restrictOnDelete();
            $table->foreign('reward_rule_id', 'sk_growth_rewards_rule_fk')->references('id')->on('suchak_growth_reward_rules')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_growth_rewards_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_growth_rewards_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_growth_rewards_payment_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'sk_growth_rewards_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('platform_payout_id', 'sk_growth_rewards_payout_fk')->references('id')->on('suchak_platform_payouts')->restrictOnDelete();
            $table->foreign('qualified_by_admin_user_id', 'sk_growth_rewards_qualified_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversed_by_admin_user_id', 'sk_growth_rewards_reversed_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_growth_reward_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('growth_reward_id')->nullable();
            $table->unsignedBigInteger('growth_attribution_id')->nullable();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32)->default(SuchakGrowthRewardEvent::ACTOR_SYSTEM);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('event_note')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('growth_reward_id', 'sk_growth_events_reward_idx');
            $table->index('growth_attribution_id', 'sk_growth_events_attr_idx');
            $table->index('suchak_account_id', 'sk_growth_events_account_idx');
            $table->index('event_type', 'sk_growth_events_type_idx');
            $table->index('actor_user_id', 'sk_growth_events_actor_idx');
            $table->index('occurred_at', 'sk_growth_events_time_idx');

            $table->foreign('growth_reward_id', 'sk_growth_events_reward_fk')->references('id')->on('suchak_growth_rewards')->restrictOnDelete();
            $table->foreign('growth_attribution_id', 'sk_growth_events_attr_fk')->references('id')->on('suchak_growth_attributions')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_growth_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_growth_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_growth_reward_events');
        Schema::dropIfExists('suchak_growth_rewards');
        Schema::dropIfExists('suchak_growth_reward_rules');
        Schema::dropIfExists('suchak_growth_attributions');
    }
};
