<?php

use App\Models\SuchakCustomerFamilyMember;
use App\Models\SuchakCustomerPortalLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_customer_family_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('linked_user_id')->nullable();
            $table->unsignedBigInteger('linked_matrimony_profile_id')->nullable();
            $table->string('member_role', 32)->default(SuchakCustomerFamilyMember::ROLE_FAMILY_MEMBER);
            $table->string('payer_role', 32)->default(SuchakCustomerFamilyMember::PAYER_NONE);
            $table->string('relationship_to_candidate', 80)->nullable();
            $table->string('display_name', 160)->nullable();
            $table->string('access_status', 32)->default(SuchakCustomerFamilyMember::STATUS_ACTIVE);
            $table->unsignedBigInteger('added_by_user_id');
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_cust_family_account_idx');
            $table->index('customer_context_id', 'sk_cust_family_context_idx');
            $table->index('linked_user_id', 'sk_cust_family_user_idx');
            $table->index('linked_matrimony_profile_id', 'sk_cust_family_profile_idx');
            $table->index('member_role', 'sk_cust_family_role_idx');
            $table->index('payer_role', 'sk_cust_family_payer_idx');
            $table->index('access_status', 'sk_cust_family_status_idx');
            $table->index('added_by_user_id', 'sk_cust_family_added_by_idx');
            $table->index('revoked_by_user_id', 'sk_cust_family_revoked_by_idx');
            $table->index([
                'suchak_account_id',
                'customer_context_id',
                'access_status',
            ], 'sk_cust_family_account_context_status_idx');

            $table->foreign('suchak_account_id', 'sk_cust_family_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_cust_family_context_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('linked_user_id', 'sk_cust_family_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('linked_matrimony_profile_id', 'sk_cust_family_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('added_by_user_id', 'sk_cust_family_added_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by_user_id', 'sk_cust_family_revoked_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_portal_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('payment_request_id')->nullable();
            $table->unsignedBigInteger('customer_family_member_id')->nullable();
            $table->unsignedBigInteger('issued_by_user_id');
            $table->string('token_hash', 64);
            $table->string('portal_status', 32)->default(SuchakCustomerPortalLink::STATUS_ACTIVE);
            $table->string('recipient_role', 32)->default(SuchakCustomerPortalLink::RECIPIENT_PAYER);
            $table->string('recipient_label', 160)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_name', 160)->nullable();
            $table->string('claimed_relationship_to_candidate', 80)->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'sk_cust_portal_token_unique');
            $table->index('suchak_account_id', 'sk_cust_portal_account_idx');
            $table->index('customer_context_id', 'sk_cust_portal_context_idx');
            $table->index('payment_request_id', 'sk_cust_portal_request_idx');
            $table->index('customer_family_member_id', 'sk_cust_portal_family_idx');
            $table->index('issued_by_user_id', 'sk_cust_portal_issued_by_idx');
            $table->index('portal_status', 'sk_cust_portal_status_idx');
            $table->index('recipient_role', 'sk_cust_portal_recipient_idx');
            $table->index('expires_at', 'sk_cust_portal_expires_idx');
            $table->index('revoked_by_user_id', 'sk_cust_portal_revoked_by_idx');
            $table->index([
                'suchak_account_id',
                'customer_context_id',
                'portal_status',
            ], 'sk_cust_portal_account_context_status_idx');

            $table->foreign('suchak_account_id', 'sk_cust_portal_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_cust_portal_context_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('payment_request_id', 'sk_cust_portal_request_fk')->references('id')->on('suchak_payment_requests')->restrictOnDelete();
            $table->foreign('customer_family_member_id', 'sk_cust_portal_family_fk')->references('id')->on('suchak_customer_family_members')->restrictOnDelete();
            $table->foreign('issued_by_user_id', 'sk_cust_portal_issued_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by_user_id', 'sk_cust_portal_revoked_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_portal_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_portal_link_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('event_note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index('customer_portal_link_id', 'sk_cust_portal_events_link_idx');
            $table->index('suchak_account_id', 'sk_cust_portal_events_account_idx');
            $table->index('customer_context_id', 'sk_cust_portal_events_context_idx');
            $table->index('event_type', 'sk_cust_portal_events_type_idx');
            $table->index('actor_type', 'sk_cust_portal_events_actor_type_idx');
            $table->index('actor_user_id', 'sk_cust_portal_events_actor_idx');
            $table->index('occurred_at', 'sk_cust_portal_events_occurred_idx');

            $table->foreign('customer_portal_link_id', 'sk_cust_portal_events_link_fk')->references('id')->on('suchak_customer_portal_links')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_cust_portal_events_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_cust_portal_events_context_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sk_cust_portal_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_customer_portal_events');
        Schema::dropIfExists('suchak_customer_portal_links');
        Schema::dropIfExists('suchak_customer_family_members');
    }
};
