<?php

namespace Tests\Feature;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\ProfilePhotoAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The list card and the profile detail screen must agree about the same profile: either both offer
 * a photo or neither does. They used to disagree — the card read an existence-checked resolver while
 * the album built URLs straight off `profile_photo`, so a profile whose file had been deleted showed
 * nothing on the card and a broken image on detail.
 */
class ProfileListDetailPhotoParityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdPhotoFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPhotoFiles as $abs) {
            @unlink($abs);
        }
        $this->createdPhotoFiles = [];

        parent::tearDown();
    }

    private function storePhotoFile(string $relativePath): string
    {
        $abs = storage_path('app/public/matrimony_photos/'.$relativePath);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0755, true);
        }
        file_put_contents($abs, 'x');
        $this->createdPhotoFiles[] = $abs;

        return $relativePath;
    }

    private function genderId(string $key): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true]
        )->id;
    }

    private function makeViewer(): User
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->for($user)->create([
            'gender_id' => $this->genderId('male'),
            'full_name' => 'Viewer',
            'profile_photo' => '',
            'photo_approved' => true,
        ]);

        return $user->fresh();
    }

    private function makeSubject(?string $legacyPhoto): MatrimonyProfile
    {
        return MatrimonyProfile::factory()->for(User::factory()->create())->create([
            'gender_id' => $this->genderId('female'),
            'full_name' => 'Subject',
            'profile_photo' => $legacyPhoto,
            'photo_approved' => true,
        ]);
    }

    /**
     * Every photo URL the detail screen can reach: the hero and every album slot.
     *
     * @return list<string>
     */
    private function detailPhotoUrls(MatrimonyProfile $subject, User $viewer): array
    {
        $display = app(MobileProfileDisplayPresenter::class)->forProfile($subject, $viewer);

        $urls = array_column($display['photo_album']['slots'] ?? [], 'url');
        $hero = $display['hero']['primary_photo_url'] ?? null;
        if (is_string($hero) && $hero !== '') {
            $urls[] = $hero;
        }

        return array_values(array_unique($urls));
    }

    #[Test]
    public function profile_whose_photo_file_is_missing_offers_no_photo_on_either_screen(): void
    {
        // The production shape this bug came from: `profile_photo` still holds a filename, the
        // gallery row is approved, but the bytes are gone from disk.
        $subject = $this->makeSubject('0428cf15-dde0-4748-8965-c3f89c1cee7c.webp');
        ProfilePhoto::query()->create([
            'profile_id' => $subject->id,
            'file_path' => '0428cf15-dde0-4748-8965-c3f89c1cee7c.webp',
            'is_primary' => true,
            'sort_order' => 0,
            'uploaded_via' => 'web',
            'approved_status' => 'approved',
            'watermark_detected' => false,
        ]);

        $viewer = $this->makeViewer();
        $card = app(MobileProfileDisplayPresenter::class)->forListCard($subject->fresh(), $viewer);

        $this->assertNull($card['card']['primary_photo_url'], 'card must not offer a missing file');
        $this->assertSame(0, $card['card']['photo_count']);
        $this->assertSame(
            [],
            $this->detailPhotoUrls($subject->fresh(), $viewer),
            'detail must not offer a photo the card suppressed as missing'
        );
    }

    #[Test]
    public function profile_with_a_real_file_offers_the_same_primary_photo_on_both_screens(): void
    {
        $subject = $this->makeSubject($this->storePhotoFile('parity-legacy.jpg'));
        $viewer = $this->makeViewer();

        $card = app(MobileProfileDisplayPresenter::class)->forListCard($subject->fresh(), $viewer);
        $detailUrls = $this->detailPhotoUrls($subject->fresh(), $viewer);

        $this->assertNotNull($card['card']['primary_photo_url']);
        $this->assertContains(
            $card['card']['primary_photo_url'],
            $detailUrls,
            'the card photo must be reachable on the detail screen'
        );
        $this->assertSame($card['card']['primary_photo_url'], $detailUrls[0], 'both screens lead with the same photo');
    }

    #[Test]
    public function profile_with_no_photo_at_all_stays_null_on_both_screens(): void
    {
        $subject = $this->makeSubject(null);
        $viewer = $this->makeViewer();

        $card = app(MobileProfileDisplayPresenter::class)->forListCard($subject->fresh(), $viewer);

        $this->assertNull($card['card']['primary_photo_url']);
        $this->assertSame([], $this->detailPhotoUrls($subject->fresh(), $viewer));
    }

    #[Test]
    public function album_drops_only_the_missing_photo_and_keeps_the_real_one(): void
    {
        $subject = $this->makeSubject(null);
        foreach ([['real-a.jpg', true], ['gone-b.jpg', false]] as [$name, $exists]) {
            if ($exists) {
                $this->storePhotoFile($name);
            }
            ProfilePhoto::query()->create([
                'profile_id' => $subject->id,
                'file_path' => $name,
                'is_primary' => $exists,
                'sort_order' => $exists ? 0 : 1,
                'uploaded_via' => 'web',
                'approved_status' => 'approved',
                'watermark_detected' => false,
            ]);
        }

        $viewer = $this->makeViewer();
        $slots = app(ProfilePhotoAccessService::class)
            ->buildAlbumPresentation($viewer, $subject->fresh(), false)['slots'];

        $this->assertCount(1, $slots);
        $this->assertStringContainsString('real-a.jpg', $slots[0]['url']);
    }

    #[Test]
    public function api_never_hands_out_a_filename_whose_file_is_gone(): void
    {
        // Both apps build a URL out of the raw `profile_photo` field client-side, so echoing a
        // name with no file behind it recreates the same disagreement one layer down.
        $missing = $this->makeSubject('vanished.webp');
        $this->assertNull(ProfilePhotoUrlService::apiLegacyPhotoValue($missing));

        $present = $this->makeSubject($this->storePhotoFile('still-here.webp'));
        $this->assertSame('still-here.webp', ProfilePhotoUrlService::apiLegacyPhotoValue($present));
    }
}
