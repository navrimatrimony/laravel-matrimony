<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Notifications\ContactRequestReceivedNotification;
use App\Notifications\ImageApprovedNotification;
use App\Services\Admin\PhotoModerationAdminService;
use App\Services\ContactRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationWiringStep2Test extends TestCase
{
    use RefreshDatabase;

    public function test_contact_request_received_notifies_receiver(): void
    {
        Notification::fake();

        [$sender, $receiver] = $this->pairWithAcceptedInterest();

        $request = app(ContactRequestService::class)->createRequest(
            $sender,
            $receiver,
            'meet',
            ['phone'],
        );

        Notification::assertSentTo(
            $receiver,
            ContactRequestReceivedNotification::class,
            function (ContactRequestReceivedNotification $notification) use ($request): bool {
                return (int) $notification->contactRequest->id === (int) $request->id;
            },
        );
    }

    public function test_contact_request_received_skipped_when_sender_is_admin(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $receiver = User::factory()->create();
        $adminProfile = MatrimonyProfile::withoutEvents(fn () => MatrimonyProfile::factory()->for($admin)->create(['lifecycle_state' => 'draft']));
        $receiverProfile = MatrimonyProfile::withoutEvents(fn () => MatrimonyProfile::factory()->for($receiver)->create(['lifecycle_state' => 'draft']));

        Interest::query()->create([
            'sender_profile_id' => $adminProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'accepted',
        ]);

        app(ContactRequestService::class)->createRequest($admin, $receiver, 'meet', ['phone']);

        Notification::assertNothingSent();
    }

    public function test_image_approved_notifies_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $profile = MatrimonyProfile::withoutEvents(fn () => MatrimonyProfile::factory()->for($owner)->create(['lifecycle_state' => 'draft']));
        $photo = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'test/photo.jpg',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
            'moderation_scan_json' => ['api_status' => 'safe'],
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        app(PhotoModerationAdminService::class)->applyPhotoAction($photo, 'approve', $admin, 'Approved for profile display');

        Notification::assertSentTo($owner, ImageApprovedNotification::class);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pairWithAcceptedInterest(): array
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        $senderProfile = MatrimonyProfile::withoutEvents(fn () => MatrimonyProfile::factory()->for($sender)->create(['lifecycle_state' => 'draft']));
        $receiverProfile = MatrimonyProfile::withoutEvents(fn () => MatrimonyProfile::factory()->for($receiver)->create(['lifecycle_state' => 'draft']));

        Interest::query()->create([
            'sender_profile_id' => $senderProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'accepted',
        ]);

        return [$sender, $receiver];
    }
}
