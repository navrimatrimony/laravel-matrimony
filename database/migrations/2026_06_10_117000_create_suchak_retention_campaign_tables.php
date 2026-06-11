<?php

use App\Models\SuchakCampaignQualification;
use App\Models\SuchakCampaignRule;
use App\Models\SuchakLoyaltyTierSnapshot;
use App\Models\SuchakMonthlyValueReport;
use App\Models\SuchakPolicy;
use App\Models\SuchakRetentionOffer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_campaign_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('campaign_key', 96)->unique('sk_campaign_rules_key_unique');
            $table->string('campaign_name', 160);
            $table->string('campaign_goal', 64);
            $table->string('qualification_metric', 64);
            $table->decimal('threshold_value', 12, 2)->default(0);
            $table->string('bonus_type', 64);
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->string('bonus_currency', 3)->default('INR');
            $table->string('campaign_status', 32)->default(SuchakCampaignRule::STATUS_ACTIVE);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamps();

            $table->index('campaign_goal', 'sk_campaign_rules_goal_idx');
            $table->index('qualification_metric', 'sk_campaign_rules_metric_idx');
            $table->index('bonus_type', 'sk_campaign_rules_bonus_idx');
            $table->index('campaign_status', 'sk_campaign_rules_status_idx');
            $table->index('created_by_admin_user_id', 'sk_campaign_rules_admin_idx');

            $table->foreign('created_by_admin_user_id', 'sk_campaign_rules_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_campaign_rules_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_campaign_qualifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_rule_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('qualification_month', 7);
            $table->decimal('metric_value', 12, 2)->default(0);
            $table->string('qualification_status', 32)->default(SuchakCampaignQualification::STATUS_QUALIFIED);
            $table->string('bonus_type', 64);
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->string('bonus_currency', 3)->default('INR');
            $table->text('qualification_note');
            $table->unsignedBigInteger('qualified_by_admin_user_id');
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamp('qualified_at');
            $table->timestamps();

            $table->unique([
                'campaign_rule_id',
                'suchak_account_id',
                'qualification_month',
            ], 'sk_campaign_qual_rule_account_month_unique');
            $table->index('suchak_account_id', 'sk_campaign_qual_account_idx');
            $table->index('qualification_month', 'sk_campaign_qual_month_idx');
            $table->index('qualification_status', 'sk_campaign_qual_status_idx');
            $table->index('qualified_by_admin_user_id', 'sk_campaign_qual_admin_idx');

            $table->foreign('campaign_rule_id', 'sk_campaign_qual_rule_fk')->references('id')->on('suchak_campaign_rules')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_campaign_qual_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('qualified_by_admin_user_id', 'sk_campaign_qual_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_campaign_qual_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_loyalty_tier_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('snapshot_month', 7);
            $table->string('policy_key', 160);
            $table->string('tier_key', 64);
            $table->string('tier_label', 120);
            $table->unsignedInteger('tier_score')->default(0);
            $table->unsignedInteger('platform_leads_count')->default(0);
            $table->decimal('platform_value_amount', 12, 2)->default(0);
            $table->unsignedInteger('verified_representation_count')->default(0);
            $table->unsignedInteger('active_customer_count')->default(0);
            $table->unsignedBigInteger('generated_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique([
                'suchak_account_id',
                'snapshot_month',
            ], 'sk_loyalty_snap_account_month_unique');
            $table->index('tier_key', 'sk_loyalty_snap_tier_idx');
            $table->index('generated_by_admin_user_id', 'sk_loyalty_snap_admin_idx');

            $table->foreign('suchak_account_id', 'sk_loyalty_snap_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('generated_by_admin_user_id', 'sk_loyalty_snap_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_loyalty_snap_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_monthly_value_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('report_month', 7);
            $table->unsignedBigInteger('loyalty_tier_snapshot_id')->nullable();
            $table->unsignedInteger('platform_leads_count')->default(0);
            $table->decimal('platform_customer_value_amount', 12, 2)->default(0);
            $table->decimal('suchak_customer_value_amount', 12, 2)->default(0);
            $table->decimal('platform_payout_amount', 12, 2)->default(0);
            $table->decimal('campaign_bonus_amount', 12, 2)->default(0);
            $table->decimal('growth_reward_cash_amount', 12, 2)->default(0);
            $table->unsignedInteger('unsupported_claims_count')->default(0);
            $table->text('unsupported_claims_note');
            $table->string('report_status', 32)->default(SuchakMonthlyValueReport::STATUS_GENERATED);
            $table->unsignedBigInteger('generated_by_admin_user_id');
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique([
                'suchak_account_id',
                'report_month',
            ], 'sk_value_reports_account_month_unique');
            $table->index('report_status', 'sk_value_reports_status_idx');
            $table->index('generated_by_admin_user_id', 'sk_value_reports_admin_idx');

            $table->foreign('suchak_account_id', 'sk_value_reports_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('loyalty_tier_snapshot_id', 'sk_value_reports_loyalty_fk')->references('id')->on('suchak_loyalty_tier_snapshots')->restrictOnDelete();
            $table->foreign('generated_by_admin_user_id', 'sk_value_reports_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_value_reports_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_retention_offers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('monthly_value_report_id')->nullable();
            $table->string('offer_type', 64);
            $table->string('offer_status', 32)->default(SuchakRetentionOffer::STATUS_OFFERED);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('revenue_share_percent', 5, 2)->nullable();
            $table->decimal('offer_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->text('offer_note');
            $table->unsignedBigInteger('offered_by_admin_user_id');
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamp('offered_at');
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_retention_offers_account_idx');
            $table->index('monthly_value_report_id', 'sk_retention_offers_report_idx');
            $table->index('offer_type', 'sk_retention_offers_type_idx');
            $table->index('offer_status', 'sk_retention_offers_status_idx');
            $table->index('offered_by_admin_user_id', 'sk_retention_offers_admin_idx');

            $table->foreign('suchak_account_id', 'sk_retention_offers_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('monthly_value_report_id', 'sk_retention_offers_report_fk')->references('id')->on('suchak_monthly_value_reports')->restrictOnDelete();
            $table->foreign('offered_by_admin_user_id', 'sk_retention_offers_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_retention_offers_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => 'suchak_loyalty_tier_policy_json'],
            [
                'policy_value' => json_encode([
                    ['tier_key' => 'starter', 'tier_label' => 'Starter', 'minimum_score' => 0],
                    ['tier_key' => 'growth', 'tier_label' => 'Growth', 'minimum_score' => 40],
                    ['tier_key' => 'partner', 'tier_label' => 'Partner', 'minimum_score' => 70],
                ], JSON_UNESCAPED_SLASHES),
                'value_type' => SuchakPolicy::TYPE_JSON,
                'description' => 'Policy-driven Suchak loyalty tier thresholds for Day-55 retention reporting.',
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_retention_offers');
        Schema::dropIfExists('suchak_monthly_value_reports');
        Schema::dropIfExists('suchak_loyalty_tier_snapshots');
        Schema::dropIfExists('suchak_campaign_qualifications');
        Schema::dropIfExists('suchak_campaign_rules');

        SuchakPolicy::query()
            ->where('policy_key', 'suchak_loyalty_tier_policy_json')
            ->delete();
    }
};
