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
        Storage::disk('local')->put($path, str_repeat('x', 2500));

        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'document_path' => $path,
            'file_meta' => [
                'bytes' => 2500,
                'width' => 720,
                'height' => 960,
                'format' => 'webp',
            ],
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
            ->assertSee('Pending', false)
            ->assertSee('Needs review', false)
            ->assertSee('2.4 KB', false)
            ->assertSee('WEBP', false)
            ->assertSee('720×960', false)
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
                'return_queue' => PhotoReviewController::QUEUE_HUMAN_REVIEWED,
            ])
            ->assertRedirect(route('admin.suchak.photo-reviews.index', [
                'queue' => PhotoReviewController::QUEUE_HUMAN_REVIEWED,
            ]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('suchak_verification_records', [
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', [
                'queue' => PhotoReviewController::QUEUE_HUMAN_REVIEWED,
            ]))
            ->assertOk()
            ->assertSee('Photo Queue Suchak', false)
            ->assertSee('Approved', false)
            ->assertSee('Admin reviewed', false);
    }

    public function test_photo_review_tabs_separate_queues_including_human_reviewed(): void
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

        $humanUser = User::factory()->create([
            'mobile' => '9876505557',
            'mobile_verified_at' => now(),
        ]);
        $humanAccount = SuchakAccount::factory()->create([
            'user_id' => $humanUser->id,
            'suchak_name' => 'Human Reviewed Suchak',
            'mobile_number' => '9876505557',
        ]);

        $safePath = 'suchak/verification-documents/'.$account->id.'/safe.webp';
        $unsafePath = 'suchak/verification-documents/'.$account->id.'/unsafe.webp';
        $reviewPath = 'suchak/verification-documents/'.$account->id.'/review.webp';
        $humanPath = 'suchak/verification-documents/'.$humanAccount->id.'/human.webp';
        Storage::disk('local')->put($safePath, str_repeat('s', 2048));
        Storage::disk('local')->put($unsafePath, str_repeat('u', 3072));
        Storage::disk('local')->put($reviewPath, str_repeat('r', 1024));
        Storage::disk('local')->put($humanPath, str_repeat('h', 4096));

        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'document_path' => $safePath,
            'file_meta' => ['bytes' => 2048, 'width' => 720, 'height' => 960, 'format' => 'webp'],
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_SAFE,
            'verified_at' => now(),
        ]);
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            'document_path' => $unsafePath,
            'file_meta' => ['bytes' => 3072, 'width' => 720, 'height' => 960, 'format' => 'webp'],
            'admin_status' => SuchakVerificationRecord::STATUS_REJECTED,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_REJECTED,
            'rejected_at' => now(),
        ]);
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
            'document_path' => $reviewPath,
            'file_meta' => ['bytes' => 1024, 'width' => 720, 'height' => 960, 'format' => 'webp'],
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_REVIEW,
        ]);
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $humanAccount->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'document_path' => $humanPath,
            'file_meta' => ['bytes' => 4096, 'width' => 720, 'height' => 960, 'format' => 'webp'],
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_REVIEW,
            'admin_user_id' => $admin->id,
            'remarks' => 'Approved from Suchak photo review queue.',
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', ['queue' => PhotoReviewController::QUEUE_AUTO_PASSED]))
            ->assertOk()
            ->assertSee('Profile photo', false)
            ->assertSee('By AI (auto-passed)', false)
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

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', ['queue' => PhotoReviewController::QUEUE_HUMAN_REVIEWED]))
            ->assertOk()
            ->assertSee('Human Reviewed Suchak', false)
            ->assertSee('Approved', false)
            ->assertSee('4 KB', false);

        $counts = PhotoReviewController::queueCounts();
        $this->assertSame(1, $counts[PhotoReviewController::QUEUE_NEEDS_REVIEW]);
        $this->assertSame(1, $counts[PhotoReviewController::QUEUE_AUTO_REJECTED]);
        $this->assertSame(1, $counts[PhotoReviewController::QUEUE_AUTO_PASSED]);
        $this->assertSame(1, $counts[PhotoReviewController::QUEUE_HUMAN_REVIEWED]);
    }
}
