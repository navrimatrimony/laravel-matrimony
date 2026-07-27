<?php

namespace Tests\Feature\Api;

use App\Models\AdminSetting;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\Profile\ProfileViewLockBlurPolicy;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin blur-strength dial must reach the member app instead of being
 * silently ignored while the app guesses its own blur.
 */
class MobileProfileAlbumBlurStrengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    private function createActiveProfileWithResidence(User $user, array $factoryAttributes = []): MatrimonyProfile
    {
        $p = MatrimonyProfile::factory()->for($user)->create(array_merge([
            'lifecycle_state' => 'draft',
        ], $factoryAttributes));
        $tbl = $p->getTable();
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($tbl, 'location_id')) {
            DB::table($tbl)->where('id', $p->id)->update(['location_id' => $leafId]);
            $p->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $p->id, $leafId, null, true, false);
        }
        $p->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $p->fresh();
    }

    #[Test]
    public function shipped_default_strength_keeps_the_blur_both_clients_already_render(): void
    {
        $policy = app(ProfileViewLockBlurPolicy::class);

        // 78 is the default; 40px === Tailwind blur-2xl, which the web album
        // renders and which the member app resolves to its current sigma 18.
        $this->assertSame(ProfileViewLockBlurPolicy::DEFAULT_STRENGTH, $policy->strength());
        $this->assertSame(40, $policy->photoBlurCssPx());
        $this->assertSame('blur-[40px] scale-105 opacity-100', $policy->photoBlurClass());
    }

    #[Test]
    public function strength_is_clamped_and_maps_monotonically_across_the_admin_range(): void
    {
        $policy = app(ProfileViewLockBlurPolicy::class);

        $this->assertSame(35, ProfileViewLockBlurPolicy::clamp(0));
        $this->assertSame(100, ProfileViewLockBlurPolicy::clamp(999));
        $this->assertSame(35, ProfileViewLockBlurPolicy::clamp('not a number'));

        $this->assertSame(12, $policy->photoBlurCssPx(35));
        $this->assertSame(64, $policy->photoBlurCssPx(100));

        $previous = 0;
        foreach (range(35, 100) as $strength) {
            $px = $policy->photoBlurCssPx($strength);
            $this->assertGreaterThanOrEqual($previous, $px, "strength {$strength} must not blur less than {$previous}");
            $previous = $px;
        }
    }

    #[Test]
    public function photo_album_payload_carries_the_admin_blur_strength(): void
    {
        AdminSetting::setValue(ProfileViewLockBlurPolicy::SETTING_KEY, '100');

        $owner = User::factory()->create();
        $profile = $this->createActiveProfileWithResidence($owner);

        $display = app(MobileProfileDisplayPresenter::class)->forProfile($profile->fresh());

        $this->assertArrayHasKey('photo_album', $display);
        $this->assertArrayHasKey('blur_photo_class', $display['photo_album']);
        $this->assertSame('blur-[64px] scale-105 opacity-100', $display['photo_album']['blur_photo_class']);

        // Additive only: the per-photo access decision keys are untouched.
        foreach (['slots', 'message_key', 'tier', 'photo_count', 'has_locked_photos'] as $key) {
            $this->assertArrayHasKey($key, $display['photo_album'], "existing key {$key} must survive");
        }
    }

    #[Test]
    public function lowering_the_admin_dial_lowers_what_the_mobile_payload_reports(): void
    {
        AdminSetting::setValue(ProfileViewLockBlurPolicy::SETTING_KEY, '35');

        $owner = User::factory()->create();
        $profile = $this->createActiveProfileWithResidence($owner);

        $display = app(MobileProfileDisplayPresenter::class)->forProfile($profile->fresh());

        $this->assertSame('blur-[12px] scale-105 opacity-100', $display['photo_album']['blur_photo_class']);
    }
}
