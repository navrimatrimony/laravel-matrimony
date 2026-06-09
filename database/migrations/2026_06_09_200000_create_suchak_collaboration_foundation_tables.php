<?php

use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_collaboration_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('requesting_suchak_account_id');
            $table->unsignedBigInteger('target_suchak_account_id');
            $table->unsignedBigInteger('requesting_matrimony_profile_id');
            $table->unsignedBigInteger('target_matrimony_profile_id');
            $table->unsignedBigInteger('requesting_representation_id');
            $table->unsignedBigInteger('target_representation_id');
            $table->string('status')->default(SuchakCollaborationRequest::STATUS_PENDING);
            $table->text('message')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('requesting_suchak_account_id', 'suchak_collab_requesting_account_idx');
            $table->index('target_suchak_account_id', 'suchak_collab_target_account_idx');
            $table->index('requesting_matrimony_profile_id', 'suchak_collab_requesting_profile_idx');
            $table->index('target_matrimony_profile_id', 'suchak_collab_target_profile_idx');
            $table->index('requesting_representation_id', 'suchak_collab_requesting_repr_idx');
            $table->index('target_representation_id', 'suchak_collab_target_repr_idx');
            $table->index('status');
            $table->index('expires_at');
            $table->index('created_at');
            $table->index([
                'requesting_suchak_account_id',
                'target_suchak_account_id',
                'requesting_matrimony_profile_id',
                'target_matrimony_profile_id',
                'status',
            ], 'suchak_collab_pair_status_idx');

            $table->foreign('requesting_suchak_account_id', 'suchak_collab_req_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('target_suchak_account_id', 'suchak_collab_target_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('requesting_matrimony_profile_id', 'suchak_collab_req_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('target_matrimony_profile_id', 'suchak_collab_target_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('requesting_representation_id', 'suchak_collab_req_repr_fk')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
            $table->foreign('target_representation_id', 'suchak_collab_target_repr_fk')->references('id')->on('suchak_profile_representations')->restrictOnDelete();
        });

        Schema::create('suchak_commission_agreements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collaboration_request_id');
            $table->unsignedBigInteger('groom_side_suchak_account_id');
            $table->unsignedBigInteger('bride_side_suchak_account_id');
            $table->string('agreement_type')->default(SuchakCommissionAgreement::TYPE_COLLABORATION_ACK);
            $table->string('split_type')->default(SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED);
            $table->decimal('groom_side_share', 5, 2)->nullable();
            $table->decimal('bride_side_share', 5, 2)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->text('agreement_text_snapshot');
            $table->timestamp('accepted_by_groom_suchak_at')->nullable();
            $table->timestamp('accepted_by_bride_suchak_at')->nullable();
            $table->string('agreement_status')->default(SuchakCommissionAgreement::STATUS_PENDING);
            $table->timestamps();

            $table->unique('collaboration_request_id', 'suchak_commission_collab_unique');
            $table->index('groom_side_suchak_account_id', 'suchak_commission_groom_account_idx');
            $table->index('bride_side_suchak_account_id', 'suchak_commission_bride_account_idx');
            $table->index('agreement_type');
            $table->index('agreement_status');
            $table->index('created_at');

            $table->foreign('collaboration_request_id', 'suchak_commission_collab_fk')->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
            $table->foreign('groom_side_suchak_account_id', 'suchak_commission_groom_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('bride_side_suchak_account_id', 'suchak_commission_bride_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_commission_agreements');
        Schema::dropIfExists('suchak_collaboration_requests');
    }
};
