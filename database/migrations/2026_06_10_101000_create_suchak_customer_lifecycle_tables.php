<?php

use App\Models\SuchakCustomerContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_customer_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('candidate_matrimony_profile_id')->nullable();
            $table->unsignedBigInteger('source_link_id')->nullable();
            $table->unsignedBigInteger('representation_id')->nullable();
            $table->unsignedBigInteger('payer_user_id')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_relationship_to_candidate')->nullable();
            $table->unsignedBigInteger('consent_id')->nullable();
            $table->unsignedBigInteger('consent_giver_user_id')->nullable();
            $table->string('consent_giver_name')->nullable();
            $table->string('consent_giver_relationship_to_candidate')->nullable();
            $table->string('service_context')->default(SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION);
            $table->string('source_owner')->default(SuchakCustomerContext::SOURCE_OWNER_SUCHAK);
            $table->string('source_type')->default(SuchakCustomerContext::SOURCE_TYPE_INTAKE_UPLOAD);
            $table->string('customer_lifecycle_status')->default(SuchakCustomerContext::STATUS_LEAD);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('classified_by_user_id')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique('source_link_id', 'suchak_customer_source_unique');
            $table->unique('representation_id', 'suchak_customer_repr_unique');
            $table->index('suchak_account_id', 'suchak_customer_account_idx');
            $table->index('candidate_matrimony_profile_id', 'suchak_customer_candidate_idx');
            $table->index('payer_user_id', 'suchak_customer_payer_user_idx');
            $table->index('consent_id', 'suchak_customer_consent_idx');
            $table->index('consent_giver_user_id', 'suchak_customer_consent_user_idx');
            $table->index('service_context', 'suchak_customer_service_idx');
            $table->index('source_owner', 'suchak_customer_owner_idx');
            $table->index('source_type', 'suchak_customer_source_type_idx');
            $table->index('customer_lifecycle_status', 'suchak_customer_lifecycle_idx');
            $table->index('created_by_user_id', 'suchak_customer_created_by_idx');
            $table->index('classified_by_user_id', 'suchak_customer_classified_by_idx');
            $table->index('created_at', 'suchak_customer_created_idx');
            $table->index([
                'suchak_account_id',
                'customer_lifecycle_status',
                'created_at',
            ], 'suchak_customer_account_status_idx');

            $table->foreign('suchak_account_id', 'suchak_customer_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('candidate_matrimony_profile_id', 'suchak_customer_candidate_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('source_link_id', 'suchak_customer_source_fk')->references('id')->on('suchak_biodata_intake_links')->restrictOnDelete();
            $table->foreign('representation_id', 'suchak_customer_repr_fk')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
            $table->foreign('payer_user_id', 'suchak_customer_payer_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('consent_id', 'suchak_customer_consent_fk')->references('id')->on('suchak_consents')->restrictOnDelete();
            $table->foreign('consent_giver_user_id', 'suchak_customer_consent_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'suchak_customer_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('classified_by_user_id', 'suchak_customer_classified_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_context_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('candidate_matrimony_profile_id')->nullable();
            $table->string('event_type');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('event_note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index('customer_context_id', 'suchak_customer_event_context_idx');
            $table->index('suchak_account_id', 'suchak_customer_event_account_idx');
            $table->index('candidate_matrimony_profile_id', 'suchak_customer_event_candidate_idx');
            $table->index('event_type', 'suchak_customer_event_type_idx');
            $table->index('actor_type', 'suchak_customer_event_actor_type_idx');
            $table->index('actor_user_id', 'suchak_customer_event_actor_idx');
            $table->index('occurred_at', 'suchak_customer_event_occurred_idx');
            $table->index([
                'suchak_account_id',
                'event_type',
                'occurred_at',
            ], 'suchak_customer_event_account_type_idx');

            $table->foreign('customer_context_id', 'suchak_customer_event_context_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'suchak_customer_event_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('candidate_matrimony_profile_id', 'suchak_customer_event_candidate_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('actor_user_id', 'suchak_customer_event_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('suchak_payment_contexts', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_context_id')->nullable()->after('id');
            $table->index('customer_context_id', 'suchak_pay_context_customer_idx');
            $table->foreign('customer_context_id', 'suchak_pay_context_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_payment_contexts', function (Blueprint $table): void {
            $table->dropForeign('suchak_pay_context_customer_fk');
            $table->dropIndex('suchak_pay_context_customer_idx');
            $table->dropColumn('customer_context_id');
        });

        Schema::dropIfExists('suchak_customer_timeline_events');
        Schema::dropIfExists('suchak_customer_contexts');
    }
};
