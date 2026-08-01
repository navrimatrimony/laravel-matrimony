<?php

use App\Models\SuchakCollaborationRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint section 6.1 — the engagement (assist) object already exists as
 * suchak_collaboration_requests + suchak_commission_agreements. Only three additive facts were missing.
 *
 * 1. customer_owner_side  — the pair is named by DIRECTION (requesting/target); the marketplace
 *                           responder is the requester, so direction no longer implies ROLE.
 *                           This names which existing account column OWNS the customer. It stores a
 *                           side label, never a second copy of an account id.
 * 2. customer_agreement_id — the customer agreement REVISION in force when the engagement was formed.
 *                           Revisions are ROWS in suchak_customer_agreements (agreement_revision +
 *                           supersedes_agreement_id), so the revision IS an id — no second table.
 * 3. marketplace_stage    — the marketplace ladder position (blueprint section 6a), ON TOP of the
 *                           existing pending/accepted/rejected/expired lifecycle in `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_collaboration_requests', function (Blueprint $table): void {
            $table->string('customer_owner_side', 16)
                ->default(SuchakCollaborationRequest::SIDE_TARGET)
                ->after('target_representation_id');
            $table->string('marketplace_stage', 64)->nullable()->after('status');

            $table->index('customer_owner_side', 'suchak_collab_owner_side_idx');
            $table->index('marketplace_stage', 'suchak_collab_marketplace_stage_idx');
        });

        Schema::table('suchak_commission_agreements', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_agreement_id')->nullable()->after('collaboration_request_id');

            $table->index('customer_agreement_id', 'suchak_commission_customer_agr_idx');
            $table->foreign('customer_agreement_id', 'suchak_commission_customer_agr_fk')
                ->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_commission_agreements', function (Blueprint $table): void {
            $table->dropForeign('suchak_commission_customer_agr_fk');
            $table->dropIndex('suchak_commission_customer_agr_idx');
            $table->dropColumn('customer_agreement_id');
        });

        Schema::table('suchak_collaboration_requests', function (Blueprint $table): void {
            $table->dropIndex('suchak_collab_owner_side_idx');
            $table->dropIndex('suchak_collab_marketplace_stage_idx');
            $table->dropColumn(['customer_owner_side', 'marketplace_stage']);
        });
    }
};
