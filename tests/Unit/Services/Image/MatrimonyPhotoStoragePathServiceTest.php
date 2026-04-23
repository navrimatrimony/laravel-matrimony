<?php

namespace Tests\Unit\Services\Image;

use App\Models\MatrimonyPhotoBatchAllocation;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Image\MatrimonyPhotoStoragePathService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatrimonyPhotoStoragePathServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_key_from_index(): void
    {
        $this->assertSame('00', MatrimonyPhotoStoragePathService::batchKeyFromIndex(0));
        $this->assertSame('09', MatrimonyPhotoStoragePathService::batchKeyFromIndex(9));
        $this->assertSame('99', MatrimonyPhotoStoragePathService::batchKeyFromIndex(99));
        $this->assertSame('100', MatrimonyPhotoStoragePathService::batchKeyFromIndex(100));
    }

    public function test_nested_relative_path_matches_yy_mm_batch_profile_leaf(): void
    {
        $user = User::factory()->create();
        $at = Carbon::parse('2026-03-15 12:00:00', config('app.timezone'));
        $profile = MatrimonyProfile::factory()->for($user)->create([
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $path = MatrimonyPhotoStoragePathService::nestedRelativePathForNewFile((int) $profile->id, 'abc123.webp');
        $expectedPrefix = '26/03/00/'.$profile->id.'/';
        $this->assertStringStartsWith($expectedPrefix, $path);
        $this->assertSame($expectedPrefix.'abc123.webp', $path);

        $again = MatrimonyPhotoStoragePathService::nestedRelativePathForNewFile((int) $profile->id, 'abc123.webp');
        $this->assertSame($path, $again);
    }

    public function test_soft_delete_decrements_allocation_count_when_present(): void
    {
        $user = User::factory()->create();
        $at = Carbon::parse('2025-07-01 10:00:00', config('app.timezone'));
        $profile = MatrimonyProfile::factory()->for($user)->create([
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        MatrimonyPhotoStoragePathService::nestedRelativePathForNewFile((int) $profile->id, 'alloc-probe.webp');

        $allocationId = (int) $profile->fresh()->photo_batch_allocation_id;
        $this->assertGreaterThan(0, $allocationId);

        $before = (int) MatrimonyPhotoBatchAllocation::query()->whereKey($allocationId)->value('profiles_count');
        $this->assertGreaterThan(0, $before);

        $profile->fresh()->delete();

        $after = (int) MatrimonyPhotoBatchAllocation::query()->whereKey($allocationId)->value('profiles_count');
        $this->assertSame($before - 1, $after);
    }

    public function test_rejects_path_traversal(): void
    {
        $this->assertFalse(MatrimonyPhotoStoragePathService::isSafeRelativePath('../etc/passwd'));
        $this->assertFalse(MatrimonyPhotoStoragePathService::isSafeRelativePath('a/../../b'));
        $this->assertTrue(MatrimonyPhotoStoragePathService::isSafeRelativePath('engagement/female/f1.jpg'));
        $this->assertTrue(MatrimonyPhotoStoragePathService::isSafeRelativePath('26/03/00/12/x.webp'));
        $this->assertTrue(MatrimonyPhotoStoragePathService::isSafeRelativePath('flat.webp'));
    }
}
