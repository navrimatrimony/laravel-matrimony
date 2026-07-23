<?php

namespace Tests\Feature\Suchak;

use App\Http\Controllers\Admin\Suchak\PhotoReviewController;
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
            'moderation_decision' => SuchakVerificationRecord::MODERATION_REVIEW,
        ]);

        // Non-photo verification must not appear in the photo queue.
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => 'suchak/verification-documents/'.$account->id.'/id.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', ['queue' => PhotoReviewController::QUEUE_NEEDS_REVIEW]))
            ->assertOk()
            ->assertSee('Suchak photo review', false)
            ->assertSee('Review करा', false)
            ->assertSee('Photo Queue Suchak', false)
            ->assertSee('Profile photo', false)
            ->assertDontSee('Identity', false);

        $this->actingAs($admin)
            ->from(route('admin.suchak.photo-reviews.index', ['queue' => PhotoReviewController::QUEUE_NEEDS_REVIEW]))
            ->post(route('admin.suchak.accounts.verification-records.approve', [
                $account,
                SuchakVerificationRecord::query()
                    ->where('verification_type', SuchakVerificationRecord::TYPE_PROFILE_PHOTO)
                    ->firstOrFail(),
            ]), [
                'reason' => 'Approved from Suchak photo review queue.',
                'return_to' => 'photo_reviews',
                'return_queue' => PhotoReviewController::QUEUE_NEEDS_REVIEW,
            ])
            ->assertRedirect(route('admin.suchak.photo-reviews.index', [
                'queue' => PhotoReviewController::QUEUE_NEEDS_REVIEW,
            ]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
        ]);
    }

    public function test_photo_review_tabs_separate_auto_rejected_and_auto_passed(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $user = User::factory()->create([
            'mobile' => '9876505556',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Tab Queue Suchak',
            'mobile_number' => '9876505556',
        ]);

        $safePath = 'suchak/verification-documents/'.$account->id.'/safe.webp';
        $unsafePath = 'suchak/verification-documents/'.$account->id.'/unsafe.webp';
        $reviewPath = 'suchak/verification-documents/'.$account->id.'/review.webp';
        Storage::disk('local')->put($safePath, 'safe');
        Storage::disk('local')->put($unsafePath, 'unsafe');
        Storage::disk('local')->put($reviewPath, 'review');

        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'document_path' => $safePath,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_SAFE,
            'verified_at' => now(),
        ]);
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            'document_path' => $unsafePath,
            'admin_status' => SuchakVerificationRecord::STATUS_REJECTED,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_REJECTED,
            'rejected_at' => now(),
        ]);
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
            'document_path' => $reviewPath,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_REVIEW,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', ['queue' => PhotoReviewController::QUEUE_AUTO_PASSED]))
            ->assertOk()
            ->assertSee('Profile photo', false)
            ->assertDontSee('Override approve', false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', ['queue' => PhotoReviewController::QUEUE_AUTO_REJECTED]))
            ->assertOk()
            ->assertSee('Office photo', false)
            ->assertSee('Override approve', false);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', ['queue' => PhotoReviewController::QUEUE_NEEDS_REVIEW]))
            ->assertOk()
            ->assertSee('Organization logo', false)
            ->assertSee('Approve', false)
            ->assertSee('Reject', false)
            ->assertDontSee('Override approve', false);

        $counts = PhotoReviewController::queueCounts();
        $this->assertSame(1, $counts[PhotoReviewController::QUEUE_NEEDS_REVIEW]);
        $this->assertSame(1, $counts[PhotoReviewController::QUEUE_AUTO_REJECTED]);
        $this->assertSame(1, $counts[PhotoReviewController::QUEUE_AUTO_PASSED]);
    }
}
