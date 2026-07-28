<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\ProfileCompletionService;
use App\Services\ProfileSectionReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Suchak's customer screen shows what is still worth filling in, so they
 * know what to ask before they phone the customer.
 *
 * The point of these tests is the "one reading" rule: the readiness block must
 * be identical on both endpoints the app reads, and it must never call a
 * section done that the member-side completeness authority calls unfinished.
 */
class SuchakProfileReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_detail_returns_the_section_work_queue(): void
    {
        [$representationId] = $this->createRepresentation('9876507101', '9876507102');

        $readiness = $this->getJson("/api/v1/suchak/customers/{$representationId}")
            ->assertOk()
            ->json('data.customer.readiness');

        $this->assertIsArray($readiness);
        $this->assertCount(11, $readiness['sections'], 'One row per edit-screen section.');
        $this->assertSame(
            $readiness['total_sections'],
            $readiness['ready_sections'] + $readiness['partial_sections'] + $readiness['missing_sections'],
            'Every section lands in exactly one group.'
        );

        foreach ($readiness['sections'] as $section) {
            $this->assertNotSame('', trim((string) $section['label']), 'Every row needs a name the Suchak recognises.');
            $this->assertNotSame($section['key'], $section['label'], 'The label must be resolved, not the raw key.');
            $this->assertContains($section['state'], ['missing', 'partial', 'ready']);
            $this->assertGreaterThan(0, $section['total'], 'A row with no countable fields would read "0 of 0".');
            $this->assertLessThanOrEqual($section['total'], $section['filled']);
        }
    }

    /**
     * The card and the edit hub are one tap apart. They read different
     * endpoints, so the only way they cannot disagree is by carrying the same
     * block from the same service.
     */
    public function test_both_endpoints_the_app_reads_return_the_identical_block(): void
    {
        [$representationId] = $this->createRepresentation('9876507103', '9876507104');

        $fromDetail = $this->getJson("/api/v1/suchak/customers/{$representationId}")
            ->assertOk()
            ->json('data.customer.readiness');

        $fromProfile = $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertOk()
            ->json('data.completion.readiness');

        $this->assertSame($fromDetail, $fromProfile);
    }

    /**
     * The load-bearing rule. This service may only ever be STRICTER than
     * {@see ProfileCompletionService} — a section it shows as done is always
     * one that service already calls completed. If this ever inverts, the
     * Suchak app has grown a third opinion about completeness.
     */
    public function test_a_section_is_never_ready_unless_the_completeness_authority_agrees(): void
    {
        $profile = $this->partiallyFilledProfile();

        $readiness = app(ProfileSectionReadinessService::class)->forProfile($profile);

        $this->assertGreaterThan(
            0,
            $readiness['ready_sections'],
            'The fixture must actually reach "ready" somewhere, or this proves nothing.'
        );

        foreach ($readiness['sections'] as $section) {
            if ($section['state'] !== ProfileSectionReadinessService::STATE_READY) {
                continue;
            }

            $agrees = false;
            foreach ($section['completion_keys'] as $key) {
                if (ProfileCompletionService::getSectionStatus($profile, $key) === 'completed') {
                    $agrees = true;
                    break;
                }
            }

            $this->assertTrue(
                $agrees,
                "Section [{$section['key']}] reads ready while ProfileCompletionService calls it unfinished."
            );
        }
    }

    /**
     * The distinction the product owner actually asked for: a section with one
     * of seven family facts is not "done" and it is not "empty" either — it is
     * half filled, and the Suchak should be able to see which one it is before
     * dialling. Note the completeness authority already calls this section
     * completed; the census only ever makes the answer stricter, never looser.
     */
    public function test_a_barely_filled_section_reads_partial_not_ready_and_not_empty(): void
    {
        $profile = $this->partiallyFilledProfile();
        $profile->forceFill(['father_name' => 'Ramesh Patil'])->save();

        $sections = collect(
            app(ProfileSectionReadinessService::class)->forProfile($profile->refresh())['sections']
        )->keyBy('key');

        $family = $sections['family_details'];
        $this->assertSame('completed', ProfileCompletionService::getSectionStatus($profile, 'family-details'));
        $this->assertSame(ProfileSectionReadinessService::STATE_PARTIAL, $family['state']);
        $this->assertSame(1, $family['filled']);
        $this->assertSame(7, $family['total']);

        // An untouched section stays honestly empty rather than being rounded
        // into "half done".
        $this->assertSame(ProfileSectionReadinessService::STATE_MISSING, $sections['horoscope']['state']);
        $this->assertSame(0, $sections['horoscope']['filled']);
    }

    private function partiallyFilledProfile(): MatrimonyProfile
    {
        $gender = MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);

        $profile = MatrimonyProfile::factory()->create();
        $profile->forceFill([
            'full_name' => 'Readiness Candidate',
            'gender_id' => $gender->id,
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'height_cm' => 160,
            'highest_education' => 'B.Com',
            'profile_photo' => 'profiles/readiness.jpg',
            'father_name' => null,
        ])->save();

        DB::table('profile_preference_criteria')->updateOrInsert(
            ['profile_id' => $profile->id],
            ['preferred_age_min' => 24, 'created_at' => now(), 'updated_at' => now()],
        );

        return $profile->refresh();
    }

    /** @return array{0: int, 1: int} representation id, profile id */
    private function createRepresentation(string $suchakMobile, string $candidateMobile): array
    {
        MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);

        $user = User::factory()->create(['mobile' => $suchakMobile, 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Readiness Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [(int) $create->json('data.representation_id'), (int) $create->json('data.profile_id')];
    }
}
