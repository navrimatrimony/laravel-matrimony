<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEND-ONLY suggestion log for the Suchak matching feature.
 *
 * Two halves of one fact per row:
 *   1. IMPRESSION — the platform SHOWED candidate X to a Suchak for seeker S,
 *      with the score + reason breakdown as they were AT SUGGESTION TIME.
 *   2. DECISION   — what the Suchak then DID (chosen / rejected + reason code /
 *      ignored), and when.
 *
 * This is NOT `profile_matches`. `profile_matches` is a REPLACE-on-write cache:
 * MatchingService::replacePersistedMatches() deletes every row for a profile and
 * re-inserts the current top matches, so it can never answer "what did we already
 * show this seeker last month". Rows here are never deleted or replaced wholesale;
 * a decision UPDATES its own row's decision columns only.
 *
 * Idempotency key = (seeker, candidate, run_key). Re-rendering the same run does
 * not create a second row, but a LATER run (new run_key) legitimately does — that
 * is what lets a previously shown candidate re-surface after the cooling period.
 *
 * Statuses/reason codes are varchar + PHP consts on App\Models\SuchakMatchSuggestion,
 * never DB enums, matching the rest of this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suchak_match_suggestions')) {
            return;
        }

        Schema::create('suchak_match_suggestions', function (Blueprint $table): void {
            $table->id();

            // Actor: the Suchak the suggestion was shown to.
            $table->unsignedBigInteger('suchak_account_id');

            // Provenance only — nullable so a suggestion still records if the
            // representation is not resolved / not applicable. The canonical
            // "who was this for" is seeker_profile_id.
            $table->unsignedBigInteger('representation_id')->nullable();

            // The represented candidate the suggestion was FOR.
            $table->unsignedBigInteger('seeker_profile_id');

            // The profile that was suggested.
            $table->unsignedBigInteger('candidate_profile_id');

            // Batch identity. Defaults in the service to a per-day bucket
            // ("d:YYYY-MM-DD"); an explicit key may be passed for a named run.
            $table->string('run_key', 64);

            // Snapshot AT SUGGESTION TIME — deliberately frozen, never recomputed.
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('reasons_json')->nullable();

            $table->timestamp('suggested_at');

            // consts: pending / chosen / rejected / ignored
            $table->string('decision', 16)->default('pending');
            // consts: age / distance / income / kundali / education / caste /
            // marital_status / other. Only meaningful when decision = rejected.
            $table->string('rejection_reason_code', 32)->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->foreign('suchak_account_id', 'suchak_match_sugg_account_fk')
                ->references('id')->on('suchak_accounts')->cascadeOnDelete();
            $table->foreign('representation_id', 'suchak_match_sugg_repr_fk')
                ->references('id')->on('suchak_profile_representations')->nullOnDelete();
            $table->foreign('seeker_profile_id', 'suchak_match_sugg_seeker_fk')
                ->references('id')->on('matrimony_profiles')->cascadeOnDelete();
            $table->foreign('candidate_profile_id', 'suchak_match_sugg_candidate_fk')
                ->references('id')->on('matrimony_profiles')->cascadeOnDelete();

            // Idempotency: one row per pair per run.
            $table->unique(
                ['seeker_profile_id', 'candidate_profile_id', 'run_key'],
                'suchak_match_sugg_pair_run_unique'
            );

            // "What have I already shown this seeker" + the cooling-period window.
            $table->index(
                ['seeker_profile_id', 'suggested_at'],
                'suchak_match_sugg_seeker_shown_idx'
            );

            // "What did this Suchak choose / reject" (learning feed).
            $table->index(
                ['suchak_account_id', 'decision', 'decided_at'],
                'suchak_match_sugg_account_decision_idx'
            );

            // Candidate-side learning: who keeps getting rejected, and why.
            $table->index(
                ['candidate_profile_id', 'decision'],
                'suchak_match_sugg_candidate_decision_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_match_suggestions');
    }
};
