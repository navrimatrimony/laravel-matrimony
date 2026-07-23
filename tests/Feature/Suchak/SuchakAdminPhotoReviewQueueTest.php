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
        Storage::fake('public');

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

        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => 'suchak/verification-documents/'.$account->id.'/id.pdf',
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', [
                'status' => SuchakVerificationRecord::STATUS_PENDING,
            ]))
            ->assertOk()
            ->assertSee('Suchak photo review', false)
            ->assertSee('Pending', false)
            ->assertSee('Photo Queue Suchak', false)
            ->assertSee('Account pending', false)
            ->assertSee('2.4 KB', false)
            ->assertDontSee('Review करा', false)
            ->assertDontSee('Override approve', false)
            ->assertDontSee('Identity', false);

        $record = SuchakVerificationRecord::query()
            ->where('verification_type', SuchakVerificationRecord::TYPE_PROFILE_PHOTO)
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.verification-records.approve', [$account, $record]), [
                'reason' => 'Clear face photo approved by admin.',
                'return_to' => 'photo_reviews',
                'return_status' => SuchakVerificationRecord::STATUS_APPROVED,
                'return_queue' => PhotoReviewController::QUEUE_ALL,
            ])
            ->assertRedirect(route('admin.suchak.photo-reviews.index', [
                'status' => SuchakVerificationRecord::STATUS_APPROVED,
                'queue' => PhotoReviewController::QUEUE_ALL,
            ]));

        $this->assertDatabaseHas('suchak_verification_records', [
            'id' => $record->id,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
        ]);

        $account->refresh();
        $this->assertNotEmpty($account->profile_photo_path);

        $this->actingAs($admin)
            ->post(route('admin.suchak.accounts.verification-records.reject', [$account, $record->fresh()]), [
                'reason' => 'Wrong photo, reject after approve mistake.',
                'return_to' => 'photo_reviews',
                'return_status' => SuchakVerificationRecord::STATUS_REJECTED,
            ])
            ->assertRedirect();

        $account->refresh();
        $this->assertNull($account->profile_photo_path);
        $this->assertDatabaseHas('suchak_verification_records', [
            'id' => $record->id,
            'admin_status' => SuchakVerificationRecord::STATUS_REJECTED,
        ]);
    }

    public function test_photo_review_status_all_and_bulk_actions(): void
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
            'suchak_name' => 'Bulk Queue Suchak',
            'mobile_number' => '9876505556',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $pendingPath = 'suchak/verification-documents/'.$account->id.'/a.webp';
        $approvedPath = 'suchak/verification-documents/'.$account->id.'/b.webp';
        Storage::disk('local')->put($pendingPath, 'a');
        Storage::disk('local')->put($approvedPath, 'b');

        $pending = SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'document_path' => $pendingPath,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_REVIEW,
        ]);
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            'document_path' => $approvedPath,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
            'moderation_decision' => SuchakVerificationRecord::MODERATION_SAFE,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.photo-reviews.index', [
                'status' => PhotoReviewController::STATUS_ALL,
                'queue' => PhotoReviewController::QUEUE_ALL,
            ]))
            ->assertOk()
            ->assertSee('Bulk Queue Suchak', false)
            ->assertSee('Profile photo', false)
            ->assertSee('Office photo', false)
            ->assertSee('पुन्हा Reject', false)
            ->assertSee('Select all', false)
            ->assertSee('Approve selected', false);

        $this->actingAs($admin)
            ->post(route('admin.suchak.photo-reviews.bulk'), [
                'record_ids' => [$pending->id],
                'bulk_action' => 'approve',
                'reason' => 'Bulk approve clear photos now.',
                'return_status' => PhotoReviewController::STATUS_ALL,
                'return_queue' => PhotoReviewController::QUEUE_ALL,
            ])
            ->assertRedirect(route('admin.suchak.photo-reviews.index', [
                'status' => PhotoReviewController::STATUS_ALL,
                'queue' => PhotoReviewController::QUEUE_ALL,
            ]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('suchak_verification_records', [
            'id' => $pending->id,
            'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
        ]);
    }
}
