<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint section 6a — the stage ladder.
 *
 * One row per (engagement, ladder stage): who claimed it, who confirmed it, when, and an optional note.
 * The engagement is suchak_collaboration_requests (blueprint section 6.1) — there is no suchak_engagements table.
 *
 * stage_key is constrained to App\Models\SuchakCollaborationStageEvent::STAGE_LADDER in PHP; free text is
 * forbidden because installment triggers hang off these keys.
 *
 * The unique(collaboration_request_id, stage_key) index is deliberate: a stage is reached once per
 * engagement, so a tranche stage can never be claimed twice. Repeat meetings between the same pair are
 * recorded on suchak_visit_confirmations, not by a second ladder row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collaboration_request_id');
            $table->string('stage_key', 64);
            $table->string('claimed_by_actor_type', 16);
            $table->unsignedBigInteger('claimed_by_suchak_account_id')->nullable();
            $table->unsignedBigInteger('claimed_by_user_id')->nullable();
            $table->timestamp('claimed_at');
            $table->string('confirmed_by_actor_type', 16)->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('event_note')->nullable();
            $table->timestamps();

            $table->unique(['collaboration_request_id', 'stage_key'], 'suchak_collab_stage_key_unique');
            $table->index('stage_key', 'suchak_collab_stage_key_idx');
            $table->index('claimed_by_suchak_account_id', 'suchak_collab_stage_claimer_idx');
            $table->index('claimed_by_user_id', 'suchak_collab_stage_claim_user_idx');
            $table->index('confirmed_by_user_id', 'suchak_collab_stage_confirm_user_idx');
            $table->index('confirmed_at', 'suchak_collab_stage_confirmed_at_idx');
            $table->index(['collaboration_request_id', 'claimed_at'], 'suchak_collab_stage_timeline_idx');

            $table->foreign('collaboration_request_id', 'suchak_collab_stage_request_fk')
                ->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
            $table->foreign('claimed_by_suchak_account_id', 'suchak_collab_stage_claimer_fk')
                ->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('claimed_by_user_id', 'suchak_collab_stage_claim_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('confirmed_by_user_id', 'suchak_collab_stage_confirm_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_collaboration_stage_events');
    }
};
