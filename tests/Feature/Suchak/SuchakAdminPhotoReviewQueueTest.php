<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuchakAdminPhotoReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_review_queue_lists_pending_onboarding_photos(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $user = User::factory()->create([
            'mobile' => '9876505555',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Photo Queue Suchak',
            'mobile_number' => '9876505555',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $path = 'suchak/verification-documents/'.$account->id.'/face.webp';
        Storage::disk('local')->put($path, 'fake-webp');

        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'document_path' => $path,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        // Non-photo verification must not appear in the photo queue.
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => 'suchak/verification-documents/'.$account->id.'/id.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index'))
            ->assertOk()
            ->assertSee('Suchak photo review', false)
            ->assertSee('Photo Queue Suchak', false)
            ->assertSee('Profile photo', false)
            ->assertDontSee('Identity', false);

        $this->actingAs($admin)
            ->from(route('admin.suchak.photo-reviews.index'))
            ->post(route('admin.suchak.accounts.verification-records.approve', [
                $account,
                SuchakVerificationRecord::query()
                    ->where('verification_type', SuchakVerificationRecord::TYPE_PROFILE_PHOTO)
                    ->firstOrFail(),
            ]), [
                'reason' => 'Clear face photo approved from photo review queue.',
                'return_to' => 'photo_reviews',
            ])
            ->assertRedirect(route('admin.suchak.photo-reviews.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
        ]);
    }
}
