<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint section 5.1 / 6.1 / 6a — A MARKETPLACE ENGAGEMENT GETS A
 * PIPELINE WHEN IT IS ACCEPTED.
 *
 * ── THE DEAD END THIS OPENS ───────────────────────────────────────────────────────────────────
 *
 * The meeting engine hangs entirely off `suchak_pipelines`: `suchak_visit_confirmations.pipeline_id`
 * is its only anchor and there is no `collaboration_request_id` on that table. A pipeline had
 * exactly one creator — SuchakRequestPipelineService::createRequest(), which runs when a MEMBER
 * approaches a represented candidate — so an engagement formed between two Suchaks never had one.
 * The `meeting_scheduled` rung of section 6a could therefore be CLAIMED while no meeting could
 * exist behind it: production carries 20 pipelines and 0 visit confirmations.
 *
 * ── WHY `request_id` BECOMES NULLABLE, AND WHY A SECOND COLUMN SITS BESIDE IT ─────────────────
 *
 * An engagement has no `suchak_profile_requests` row and must never be given a fabricated one — a
 * forged request is a lie in the audit trail, and everything downstream (the SLA sweep, the member's
 * own sent list, the Suchak inbox) reads that table as "a member asked for this". So the column has
 * to admit NULL. That alone is not enough: with `request_id` NULL the row would name no origin at
 * all, "one pipeline per engagement" would have to be guessed from the profile pair (which can
 * legitimately carry a member-born pipeline as well), and nothing could answer "which engagement is
 * this meeting under". `collaboration_request_id` answers all three, and its `unique` is what makes
 * the idempotence the database's rather than hopeful application code's.
 *
 * The two are mutually exclusive in practice and deliberately NOT enforced by a CHECK: MySQL and
 * SQLite cannot both be given the same one through Laravel's schema builder, and the invariant is
 * asserted on the model instead (SuchakPipeline::assertExactlyOneOrigin(), on `saving`) — the same
 * shape SuchakCollaborationStageEvent::assertOwnership() already uses for its two owner columns.
 *
 * `unique(request_id)` is left in place. Both MySQL and SQLite allow repeated NULLs in a unique
 * index, so it still means exactly what it always did — one pipeline per member request.
 *
 * ── WHY `lock_expires_at` BECOMES NULLABLE ────────────────────────────────────────────────────
 *
 * It is the Suchak's REPLY clock: a member asked, and the attribution lock lapses if nobody answers
 * inside the SLA (SuchakRequestPipelineService::expireDuePipelines). An accepted engagement has
 * already been answered — by the acceptance itself — so there is no reply pending and no clock to
 * run. Writing a deadline anyway would not be harmless: SuchakDailyOpportunityService::slaRisks()
 * would show the arranging Suchak an "SLA risk / Review request" card pointing at a request that
 * does not exist. NULL is the honest value and needs no new semantics anywhere —
 * SuchakPipeline::isPastSla() already returns false on a null, the sweep's `lock_expires_at <= now`
 * already excludes it, and `slaRisks()` already filters `whereNotNull`.
 *
 * ── AND WHY THE VISIT ROW'S `request_id` FOLLOWS ──────────────────────────────────────────────
 *
 * SuchakVisitConfirmationService::scheduleVisit() copies `pipeline.request_id` onto the meeting. On
 * an engagement-born pipeline that value is NULL, so without this the very first meeting on the new
 * path would fail on a NOT NULL constraint. No `collaboration_request_id` is added to
 * `suchak_visit_confirmations`: the meeting reaches its engagement through its pipeline, and a
 * second copy of that link is precisely the duplicate the frozen rule forbids.
 *
 * Widening only. Every existing row keeps every value it has; nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_pipelines', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_id')->nullable()->change();
            $table->timestamp('lock_expires_at')->nullable()->change();
            $table->unsignedBigInteger('collaboration_request_id')->nullable()->after('request_id');
        });

        Schema::table('suchak_pipelines', function (Blueprint $table): void {
            $table->unique('collaboration_request_id', 'suchak_pipelines_collaboration_unique');
            $table->foreign('collaboration_request_id', 'suchak_pipelines_collaboration_fk')
                ->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_id')->nullable()->change();
        });
    }

    /**
     * Restores the original NOT NULL columns. If an engagement-born pipeline or its meetings exist
     * by then this will fail, and that failure is the honest statement of what the old schema meant:
     * a pipeline could only ever have come from a member's request.
     */
    public function down(): void
    {
        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_id')->nullable(false)->change();
        });

        Schema::table('suchak_pipelines', function (Blueprint $table): void {
            $table->dropForeign('suchak_pipelines_collaboration_fk');
        });

        Schema::table('suchak_pipelines', function (Blueprint $table): void {
            $table->dropUnique('suchak_pipelines_collaboration_unique');
        });

        Schema::table('suchak_pipelines', function (Blueprint $table): void {
            $table->dropColumn('collaboration_request_id');
        });

        Schema::table('suchak_pipelines', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_id')->nullable(false)->change();
            $table->timestamp('lock_expires_at')->nullable(false)->change();
        });
    }
};
