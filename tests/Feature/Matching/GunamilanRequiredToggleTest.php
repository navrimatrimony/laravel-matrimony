<?php

namespace Tests\Feature\Matching;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "गुणमिलन जुळणे आवश्यक" toggle is the ONLY way the gunamilan gate can ever
 * be switched on, and its value crosses three separate allow-lists on the way
 * to the database:
 *
 *   blade  ->  ProfileWizardController::buildHoroscopeSnapshot()   (section post)
 *          ->  ManualSnapshotBuilderService::buildFullManualSnapshot()  (full form)
 *          ->  MutationService::syncPreferencesFromSnapshot()      ($allowed list)
 *
 * Any one of them omitting the key silently drops it with no error — which is
 * exactly how the field sat unreachable after the column shipped. These tests
 * pin the whole round trip rather than any single hop.
 */
class GunamilanRequiredToggleTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        return [$user, $profile];
    }

    private function storedFlag(MatrimonyProfile $profile): ?int
    {
        $value = DB::table('profile_preference_criteria')
            ->where('profile_id', $profile->id)
            ->value('gunamilan_required');

        return $value === null ? null : (int) $value;
    }

    public function test_it_defaults_to_off_for_a_brand_new_profile(): void
    {
        [, $profile] = $this->actor();

        $this->assertNotSame(1, $this->storedFlag($profile), 'Gunamilan must be opt-in, never on by default.');
    }

    public function test_the_horoscope_section_can_turn_it_on_and_back_off(): void
    {
        [$user, $profile] = $this->actor();

        $this->actingAs($user)
            ->post(route('matrimony.profile.wizard.store', ['section' => 'horoscope']), [
                'gunamilan_required' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->storedFlag($profile), 'Checking the box must persist to preference criteria.');

        // The blade posts a hidden 0, so unchecking sends an explicit false
        // rather than dropping the key.
        $this->actingAs($user)
            ->post(route('matrimony.profile.wizard.store', ['section' => 'horoscope']), [
                'gunamilan_required' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->storedFlag($profile), 'Unchecking the box must turn the gate back off.');
    }

    public function test_a_section_that_does_not_post_the_field_leaves_it_untouched(): void
    {
        [$user, $profile] = $this->actor();

        $this->actingAs($user)
            ->post(route('matrimony.profile.wizard.store', ['section' => 'horoscope']), [
                'gunamilan_required' => '1',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->storedFlag($profile));

        // A different surface saving preferences without the checkbox (the
        // mobile apps, for one) must not silently clear the user's choice.
        $this->actingAs($user)
            ->post(route('matrimony.profile.wizard.store', ['section' => 'about-preferences']), [
                'preferred_age_min' => '24',
                'preferred_age_max' => '30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->storedFlag($profile), 'An unrelated preference save must not reset gunamilan.');
    }

    public function test_the_full_form_carries_the_flag_too(): void
    {
        [$user, $profile] = $this->actor();

        $this->actingAs($user)
            ->post(route('matrimony.profile.wizard.store', ['section' => 'full', 'all' => 1]), [
                // The full form rewrites the core row, so it needs the required
                // core fields even when the change under test is elsewhere.
                'full_name' => (string) $profile->full_name,
                'gender_id' => (int) $profile->gender_id,
                'gunamilan_required' => '1',
                'preferred_age_min' => '24',
                'preferred_age_max' => '30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->storedFlag($profile), 'The full form must not drop the flag.');
    }

    public function test_the_horoscope_screen_renders_the_toggle_reflecting_the_saved_value(): void
    {
        [$user, $profile] = $this->actor();

        $offHtml = (string) $this->actingAs($user)
            ->get(route('matrimony.profile.wizard.section', ['section' => 'horoscope']))
            ->assertOk()
            ->assertSee('name="gunamilan_required"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/name="gunamilan_required"[^>]*value="1"[^>]*checked/s',
            $offHtml,
            'A brand-new profile must render the toggle unchecked.',
        );

        DB::table('profile_preference_criteria')->updateOrInsert(
            ['profile_id' => $profile->id],
            ['gunamilan_required' => true, 'updated_at' => now(), 'created_at' => now()],
        );

        $html = $this->actingAs($user)
            ->get(route('matrimony.profile.wizard.section', ['section' => 'horoscope']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="gunamilan_required"[^>]*value="1"[^>]*checked/s',
            (string) $html,
            'A saved ON value must come back checked.',
        );
    }
}
