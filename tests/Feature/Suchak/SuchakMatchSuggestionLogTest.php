<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakMatchSuggestion;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakMatchSuggestionLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SuchakMatchSuggestionLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_suggestions_is_idempotent_within_a_run(): void
    {
        $account = $this->suchakAccount();
        $seeker = $this->profile();
        $candidateA = $this->profile();
        $candidateB = $this->profile();
        $service = app(SuchakMatchSuggestionLogService::class);

        $service->recordSuggestions($account, $seeker, [
            ['candidate_profile_id' => $candidateA->id, 'score' => 82, 'reasons' => ['caste', 'district']],
            ['candidate_profile' => $candidateB, 'score' => 71, 'reasons' => ['age']],
            // duplicate inside the same payload, plus the seeker itself
            ['candidate_profile_id' => $candidateA->id, 'score' => 99],
            ['candidate_profile_id' => $seeker->id, 'score' => 100],
        ], 'run-1');

        $this->assertSame(2, SuchakMatchSuggestion::query()->count());

        // Same run again with a refreshed score => still 2 rows, snapshot updated.
        $service->recordSuggestions($account, $seeker, [
            ['candidate_profile_id' => $candidateA->id, 'score' => 90, 'reasons' => ['caste', 'district', 'income']],
        ], 'run-1');

        $this->assertSame(2, SuchakMatchSuggestion::query()->count());

        $rowA = SuchakMatchSuggestion::query()
            ->where('candidate_profile_id', $candidateA->id)->sole();
        $this->assertSame(90, $rowA->score);
        $this->assertSame(['caste', 'district', 'income'], $rowA->reasons_json);
        $this->assertSame(SuchakMatchSuggestion::DECISION_PENDING, $rowA->decision);

        // A LATER run legitimately adds a new row for the same pair.
        $service->recordSuggestions($account, $seeker, [
            ['candidate_profile_id' => $candidateA->id, 'score' => 88],
        ], 'run-2');

        $this->assertSame(3, SuchakMatchSuggestion::query()->count());
        $this->assertSame(2, SuchakMatchSuggestion::query()
            ->where('candidate_profile_id', $candidateA->id)->count());
    }

    public function test_decision_updates_only_its_own_row_and_never_erases_history(): void
    {
        $account = $this->suchakAccount();
        $seeker = $this->profile();
        $chosen = $this->profile();
        $rejected = $this->profile();
        $ignored = $this->profile();
        $service = app(SuchakMatchSuggestionLogService::class);

        $service->recordSuggestions($account, $seeker, [
            ['candidate_profile_id' => $chosen->id, 'score' => 91],
            ['candidate_profile_id' => $rejected->id, 'score' => 64],
            ['candidate_profile_id' => $ignored->id, 'score' => 55],
        ], 'run-1');

        $service->recordDecisionForPair($seeker, $chosen, SuchakMatchSuggestion::DECISION_CHOSEN);
        $service->recordDecisionForPair(
            $seeker,
            $rejected,
            SuchakMatchSuggestion::DECISION_REJECTED,
            SuchakMatchSuggestion::REJECTION_KUNDALI,
            '  guru mangal doesn not match  ',
        );
        $service->recordDecisionForPair($seeker, $ignored, SuchakMatchSuggestion::DECISION_IGNORED);

        $chosenRow = SuchakMatchSuggestion::query()->where('candidate_profile_id', $chosen->id)->sole();
        $rejectedRow = SuchakMatchSuggestion::query()->where('candidate_profile_id', $rejected->id)->sole();
        $ignoredRow = SuchakMatchSuggestion::query()->where('candidate_profile_id', $ignored->id)->sole();

        $this->assertSame(SuchakMatchSuggestion::DECISION_CHOSEN, $chosenRow->decision);
        $this->assertNull($chosenRow->rejection_reason_code);
        $this->assertNotNull($chosenRow->decided_at);

        $this->assertSame(SuchakMatchSuggestion::DECISION_REJECTED, $rejectedRow->decision);
        $this->assertSame(SuchakMatchSuggestion::REJECTION_KUNDALI, $rejectedRow->rejection_reason_code);
        $this->assertSame('guru mangal doesn not match', $rejectedRow->rejection_note);
        $this->assertSame(64, $rejectedRow->score, 'the suggestion-time snapshot must survive the decision');

        $this->assertSame(SuchakMatchSuggestion::DECISION_IGNORED, $ignoredRow->decision);
        $this->assertNull($ignoredRow->rejection_reason_code);

        // Re-running the same run must NOT wipe the decisions already recorded.
        $service->recordSuggestions($account, $seeker, [
            ['candidate_profile_id' => $chosen->id, 'score' => 93],
            ['candidate_profile_id' => $rejected->id, 'score' => 60],
        ], 'run-1');

        $this->assertSame(
            SuchakMatchSuggestion::DECISION_CHOSEN,
            $chosenRow->fresh()->decision
        );
        $this->assertSame(
            SuchakMatchSuggestion::REJECTION_KUNDALI,
            $rejectedRow->fresh()->rejection_reason_code
        );
        $this->assertSame(93, $chosenRow->fresh()->score);

        // Learning feed index path: what did this Suchak choose / reject.
        $this->assertSame(1, SuchakMatchSuggestion::query()
            ->forSuchak($account)
            ->where('decision', SuchakMatchSuggestion::DECISION_CHOSEN)
            ->count());
        $this->assertSame(3, SuchakMatchSuggestion::query()->forSuchak($account)->decided()->count());

        $this->expectException(InvalidArgumentException::class);
        $service->recordDecision($chosenRow, 'maybe_later');
    }

    public function test_already_suggested_and_cooling_period_queries(): void
    {
        $account = $this->suchakAccount();
        $seeker = $this->profile();
        $otherSeeker = $this->profile();
        $old = $this->profile();
        $fresh = $this->profile();
        $otherSeekersCandidate = $this->profile();
        $service = app(SuchakMatchSuggestionLogService::class);

        // shown 45 days ago => cooled off
        $service->recordSuggestions($account, $seeker, [
            ['candidate_profile_id' => $old->id, 'score' => 70],
        ], 'old-run', null, now()->subDays(45));

        // shown 3 days ago => still too soon
        $service->recordSuggestions($account, $seeker, [
            ['candidate_profile_id' => $fresh->id, 'score' => 80],
        ], 'fresh-run', null, now()->subDays(3));

        // a different seeker's log must not leak in
        $service->recordSuggestions($account, $otherSeeker, [
            ['candidate_profile_id' => $otherSeekersCandidate->id, 'score' => 60],
        ], 'fresh-run');

        $already = $service->alreadySuggestedCandidateIds($seeker);
        sort($already);
        $expected = [$old->id, $fresh->id];
        sort($expected);
        $this->assertSame($expected, $already);

        $recent = $service->suggestedRecently($seeker, 30);
        $this->assertSame([$fresh->id], $recent);

        $this->assertSame([$old->id], $service->cooledOffCandidateIds($seeker, 30));

        // A long enough window puts the old one back inside the cooling set.
        $recentWide = $service->suggestedRecently($seeker, 90);
        sort($recentWide);
        $this->assertSame($expected, $recentWide);
        $this->assertSame([], $service->cooledOffCandidateIds($seeker, 90));
    }

    private function suchakAccount(): SuchakAccount
    {
        $user = User::factory()->create();

        return SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
    }

    private function profile(): MatrimonyProfile
    {
        return MatrimonyProfile::factory()->create();
    }
}
