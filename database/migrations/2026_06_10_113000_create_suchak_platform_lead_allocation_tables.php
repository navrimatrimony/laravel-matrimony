<?php

use App\Models\SuchakLeadAllocationEvent;
use App\Models\SuchakPlatformLead;
use App\Models\SuchakPlatformLeadAllocation;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_platform_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('lead_type', 40);
            $table->string('lead_source', 40)->default(SuchakPlatformLead::SOURCE_PLATFORM);
            $table->string('lead_status', 40)->default(SuchakPlatformLead::STATUS_OPEN);
            $table->string('allocation_policy', 40)->default(SuchakPlatformLead::POLICY_AREA_COMMUNITY_ROTATION);
            $table->unsignedSmallInteger('allocation_sla_hours')->default(SuchakPolicyService::DEFAULT_SUCHAK_LEAD_ALLOCATION_SLA_HOURS);
            $table->unsignedBigInteger('requesting_user_id')->nullable();
            $table->unsignedBigInteger('requesting_matrimony_profile_id')->nullable();
            $table->unsignedBigInteger('target_matrimony_profile_id')->nullable();
            $table->string('service_context', 64)->default('package_lead');
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('taluka_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('religion_id')->nullable();
            $table->unsignedBigInteger('caste_id')->nullable();
            $table->unsignedBigInteger('sub_caste_id')->nullable();
            $table->string('lead_title', 160);
            $table->text('lead_note')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->timestamp('opened_at');
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('lead_type', 'sk_platform_leads_type_idx');
            $table->index('lead_source', 'sk_platform_leads_source_idx');
            $table->index('lead_status', 'sk_platform_leads_status_idx');
            $table->index('allocation_policy', 'sk_platform_leads_policy_idx');
            $table->index('requesting_user_id', 'sk_platform_leads_user_idx');
            $table->index('requesting_matrimony_profile_id', 'sk_platform_leads_requesting_profile_idx');
            $table->index('target_matrimony_profile_id', 'sk_platform_leads_target_profile_idx');
            $table->index(['district_id', 'taluka_id', 'city_id'], 'sk_platform_leads_area_idx');
            $table->index(['religion_id', 'caste_id', 'sub_caste_id'], 'sk_platform_leads_community_idx');
            $table->index('opened_at', 'sk_platform_leads_opened_idx');

            $table->foreign('requesting_user_id', 'sk_platform_leads_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('requesting_matrimony_profile_id', 'sk_platform_leads_req_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('target_matrimony_profile_id', 'sk_platform_leads_target_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('created_by_admin_user_id', 'sk_platform_leads_admin_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_lead_allocation_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('taluka_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('religion_id')->nullable();
            $table->unsignedBigInteger('caste_id')->nullable();
            $table->unsignedBigInteger('sub_caste_id')->nullable();
            $table->unsignedSmallInteger('priority_weight')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('preference_note')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_lead_prefs_account_idx');
            $table->index(['district_id', 'taluka_id', 'city_id'], 'sk_lead_prefs_area_idx');
            $table->index(['religion_id', 'caste_id', 'sub_caste_id'], 'sk_lead_prefs_community_idx');
            $table->index('is_active', 'sk_lead_prefs_active_idx');
            $table->index('priority_weight', 'sk_lead_prefs_priority_idx');

            $table->foreign('suchak_account_id', 'sk_lead_prefs_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('created_by_admin_user_id', 'sk_lead_prefs_admin_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_platform_lead_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_lead_id')->nullable();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('payment_context_id');
            $table->string('allocation_status', 40)->default(SuchakPlatformLeadAllocation::STATUS_ALLOCATED);
            $table->string('allocation_policy', 40)->default(SuchakPlatformLead::POLICY_AREA_COMMUNITY_ROTATION);
            $table->string('rotation_bucket_key', 190);
            $table->unsignedBigInteger('rotation_sequence')->default(0);
            $table->string('matched_area_level', 32)->default(SuchakPlatformLeadAllocation::MATCH_NONE);
            $table->string('matched_community_level', 32)->default(SuchakPlatformLeadAllocation::MATCH_NONE);
            $table->unsignedInteger('plan_limit_snapshot')->nullable();
            $table->unsignedBigInteger('allocated_by_admin_user_id');
            $table->timestamp('allocated_at');
            $table->timestamp('sla_expires_at');
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('acceptance_note')->nullable();
            $table->unsignedBigInteger('declined_by_user_id')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_admin_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('status_note')->nullable();
            $table->timestamps();

            $table->unique(['platform_lead_id', 'suchak_account_id'], 'sk_platform_lead_alloc_lead_account_unique');
            $table->index('platform_lead_id', 'sk_platform_lead_alloc_lead_idx');
            $table->index('suchak_account_id', 'sk_platform_lead_alloc_account_idx');
            $table->index('customer_context_id', 'sk_platform_lead_alloc_customer_idx');
            $table->index('payment_context_id', 'sk_platform_lead_alloc_payment_idx');
            $table->index('allocation_status', 'sk_platform_lead_alloc_status_idx');
            $table->index('allocation_policy', 'sk_platform_lead_alloc_policy_idx');
            $table->index('rotation_bucket_key', 'sk_platform_lead_alloc_bucket_idx');
            $table->index('sla_expires_at', 'sk_platform_lead_alloc_sla_idx');

            $table->foreign('platform_lead_id', 'sk_platform_lead_alloc_lead_fk')->references('id')->on('suchak_platform_leads')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_platform_lead_alloc_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_platform_lead_alloc_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_context_id', 'sk_platform_lead_alloc_payment_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
            $table->foreign('allocated_by_admin_user_id', 'sk_platform_lead_alloc_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('accepted_by_user_id', 'sk_platform_lead_alloc_accepted_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('declined_by_user_id', 'sk_platform_lead_alloc_declined_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by_admin_user_id', 'sk_platform_lead_alloc_cancelled_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_lead_rotation_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('rotation_bucket_key', 190);
            $table->string('allocation_policy', 40);
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('taluka_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('religion_id')->nullable();
            $table->unsignedBigInteger('caste_id')->nullable();
            $table->unsignedBigInteger('sub_caste_id')->nullable();
            $table->unsignedBigInteger('last_allocated_suchak_account_id')->nullable();
            $table->unsignedBigInteger('last_rotation_sequence')->default(0);
            $table->timestamp('last_allocated_at')->nullable();
            $table->unsignedBigInteger('updated_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique('rotation_bucket_key', 'sk_lead_rotation_bucket_unique');
            $table->index('last_allocated_suchak_account_id', 'sk_lead_rotation_last_account_idx');

            $table->foreign('last_allocated_suchak_account_id', 'sk_lead_rotation_last_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('updated_by_admin_user_id', 'sk_lead_rotation_admin_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_lead_allocation_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_lead_id');
            $table->unsignedBigInteger('lead_allocation_id')->nullable();
            $table->unsignedBigInteger('suchak_account_id')->nullable();
            $table->string('event_type', 64);
            $table->string('actor_type', 32)->default(SuchakLeadAllocationEvent::ACTOR_SYSTEM);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('event_note')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('platform_lead_id', 'sk_lead_events_lead_idx');
            $table->index('lead_allocation_id', 'sk_lead_events_allocation_idx');
            $table->index('suchak_account_id', 'sk_lead_events_account_idx');
            $table->index('event_type', 'sk_lead_events_type_idx');
            $table->index('actor_user_id', 'sk_lead_events_actor_idx');
            $table->index('occurred_at', 'sk_lead_events_time_idx');

            $table->foreign('platform_lead_id', 'sk_lead_events_lead_fk')->references('id')->on('suchak_platform_leads')->restrictOnDelete();
            $table->foreign('lead_allocation_id', 'sk_lead_events_allocation_fk')->references('id')->on('suchak_platform_lead_allocations')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_lead_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_lead_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_LEAD_ALLOCATION_POLICY_MODE,
                'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_LEAD_ALLOCATION_POLICY_MODE,
                'value_type' => 'string',
                'description' => 'Policy mode for platform-sourced Suchak lead allocation.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_LEAD_ALLOCATION_SLA_HOURS,
                'policy_value' => (string) SuchakPolicyService::DEFAULT_SUCHAK_LEAD_ALLOCATION_SLA_HOURS,
                'value_type' => 'integer',
                'description' => 'SLA hours before a platform lead allocation can expire or rotate.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->whereIn('policy_key', [
                SuchakPolicyService::KEY_SUCHAK_LEAD_ALLOCATION_POLICY_MODE,
                SuchakPolicyService::KEY_SUCHAK_LEAD_ALLOCATION_SLA_HOURS,
            ])
            ->delete();

        Schema::dropIfExists('suchak_lead_allocation_events');
        Schema::dropIfExists('suchak_lead_rotation_cursors');
        Schema::dropIfExists('suchak_platform_lead_allocations');
        Schema::dropIfExists('suchak_lead_allocation_preferences');
        Schema::dropIfExists('suchak_platform_leads');
    }
};
