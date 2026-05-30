<?php

namespace Tests\Feature\Admin;

use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\Religion;
use App\Models\User;
use App\Services\Showcase\ShowcasePhotoPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ShowcasePhotoPoolTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdFiles) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_admin_can_upload_to_exact_eng_folder(): void
    {
        $religion = Religion::query()->firstOrCreate(['key' => 'hindu'], ['label' => 'Hindu', 'is_active' => true]);
        $marital = MasterMaritalStatus::query()->firstOrCreate(['key' => 'never_married'], ['label' => 'Never married', 'is_active' => true]);
        MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.showcase-photo-pool.store'), [
            'gender' => 'female',
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'age_bucket' => '25-30',
            'photos' => [
                UploadedFile::fake()->image('pool-upload.jpg', 120, 120),
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $dir = public_path('uploads/matrimony_photos/eng/female/hindu/never_married/25-30');
        $this->assertDirectoryExists($dir);
        $files = array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => ! in_array($f, ['.', '..'], true)));
        $this->assertCount(1, $files);
        $this->createdFiles[] = $dir.DIRECTORY_SEPARATOR.$files[0];
    }

    public function test_admin_can_delete_pool_image(): void
    {
        $relative = 'eng/female/hindu/never_married/25-30/delete-me.jpg';
        $path = public_path('uploads/matrimony_photos/'.$relative);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, 'x');
        $this->createdFiles[] = $path;

        $admin = User::factory()->create(['is_admin' => true]);
        $religion = Religion::query()->firstOrCreate(['key' => 'hindu'], ['label' => 'Hindu', 'is_active' => true]);
        $marital = MasterMaritalStatus::query()->firstOrCreate(['key' => 'never_married'], ['label' => 'Never married', 'is_active' => true]);

        $response = $this->actingAs($admin)->post(route('admin.showcase-photo-pool.destroy'), [
            'relative_path' => $relative,
            'gender' => 'female',
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'age_bucket' => '25-30',
        ]);

        $response->assertRedirect(route('admin.showcase-photo-pool.index', [
            'gender' => 'female',
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'age_bucket' => '25-30',
        ]));
        $this->assertFileDoesNotExist($path);
    }

    public function test_coverage_matrix_counts_unused_photos(): void
    {
        $relativeDir = 'eng/male/hindu/never_married/31-35';
        $path = public_path('uploads/matrimony_photos/'.$relativeDir.'/matrix-a.jpg');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, 'x');
        $this->createdFiles[] = $path;

        $matrix = app(ShowcasePhotoPoolService::class)->coverageMatrix();
        $row = collect($matrix)->firstWhere('folder', $relativeDir);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['total']);
        $this->assertSame(1, $row['unused']);
    }

    public function test_guest_cannot_access_photo_pool(): void
    {
        $this->get(route('admin.showcase-photo-pool.index'))->assertRedirect();
    }

    public function test_pool_health_summary_reflects_on_disk_buckets(): void
    {
        $relativeDir = 'eng/female/hindu/never_married/25-30';
        $path = public_path('uploads/matrimony_photos/'.$relativeDir.'/health.jpg');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, 'x');
        $this->createdFiles[] = $path;

        $health = app(ShowcasePhotoPoolService::class)->poolHealthSummary();

        $this->assertGreaterThanOrEqual(1, $health['bucket_count']);
        $this->assertGreaterThanOrEqual(1, $health['total_photos']);
    }
}
