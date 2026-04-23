<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\Interest;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\MediationRequest;
use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\User;
use App\Notifications\MediationRequestReceivedNotification;
use App\Notifications\MediationRequestResponseNotification;
use App\Services\ContactAccessService;
use App\Services\MediationRequestService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class MediationRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedGenders(): array
    {
        $male = MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        );
        $female = MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true]
        );

        return [(int) $male->id, (int) $female->id];
    }

    private function subscribeUser(User $user, string $planSlug = 'silver_male'): void
    {
        $plan = Plan::query()->where('slug', $planSlug)->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $plan->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();
        app(SubscriptionService::class)->subscribe($user, $plan, (int) $term->id, null);
    }

    public function test_create_mediation_notifies_receiver_and_consumes_credit_row(): void
    {
        Notification::fake();

        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        [$maleGid, $femaleGid] = $this->seedGenders();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        MatrimonyProfile::factory()->for($sender)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $target = MatrimonyProfile::factory()->for($receiver)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $this->subscribeUser($sender, 'silver_male');

        $svc = app(MediationRequestService::class);
        $mr = $svc->createFromProfile($sender, $target);

        $this->assertSame(MediationRequest::STATUS_PENDING, $mr->status);
        $this->assertSame(ContactRequest::TYPE_MEDIATOR, $mr->type);
        $this->assertSame($sender->id, $mr->sender_id);
        $this->assertSame($receiver->id, $mr->receiver_id);
        $this->assertNotNull($mr->admin_notified_at);

        $senderProfile = $sender->fresh()->matrimonyProfile;
        $this->assertNotNull($senderProfile);

        $this->assertDatabaseHas('contact_requests', [
            'id' => $mr->id,
            'type' => ContactRequest::TYPE_MEDIATOR,
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'sender_profile_id' => $senderProfile->id,
            'receiver_profile_id' => $target->id,
        ]);

        $this->assertNotSame('', trim((string) data_get($mr->fresh()->meta, 'matchmaking.compatibility_hint', '')));

        Notification::assertSentTo($receiver, MediationRequestReceivedNotification::class);

        $this->assertSame(1, \App\Models\UserFeatureUsage::query()
            ->where('user_id', $sender->id)
            ->where('feature_key', \App\Support\UserFeatureUsageKeys::MEDIATOR_REQUEST)
            ->value('used_count'));
    }

    public function test_receiver_response_notifies_sender(): void
    {
        Notification::fake();

        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        [$maleGid, $femaleGid] = $this->seedGenders();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        MatrimonyProfile::factory()->for($sender)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $target = MatrimonyProfile::factory()->for($receiver)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $this->subscribeUser($sender, 'silver_male');

        $mr = app(MediationRequestService::class)->createFromProfile($sender, $target);
        Notification::fake();

        app(MediationRequestService::class)->respond($receiver, $mr, 'interested');

        $mr->refresh();
        $this->assertSame(MediationRequest::STATUS_INTERESTED, $mr->status);
        $this->assertNotNull($mr->responded_at);

        Notification::assertSentTo($sender, MediationRequestResponseNotification::class);
    }

    public function test_receiver_need_more_info_stores_feedback(): void
    {
        Notification::fake();

        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        [$maleGid, $femaleGid] = $this->seedGenders();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        MatrimonyProfile::factory()->for($sender)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $target = MatrimonyProfile::factory()->for($receiver)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $this->subscribeUser($sender, 'silver_male');

        $mr = app(MediationRequestService::class)->createFromProfile($sender, $target);
        Notification::fake();

        app(MediationRequestService::class)->respond($receiver, $mr, 'need_more_info', 'Please share occupation details.');

        $mr->refresh();
        $this->assertSame(MediationRequest::STATUS_NEED_MORE_INFO, $mr->status);
        $this->assertSame('Please share occupation details.', $mr->response_feedback);
        $this->assertSame('need_more_info', data_get($mr->meta, 'matchmaking.receiver_choice'));

        Notification::assertSentTo($sender, MediationRequestResponseNotification::class);
    }

    public function test_paid_contact_reveal_requires_matchmaking_interested_by_default(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
        [$maleGid, $femaleGid] = $this->seedGenders();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        $senderProfile = MatrimonyProfile::factory()->for($sender)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $target = MatrimonyProfile::factory()->for($receiver)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        DB::table('profile_contacts')->insert([
            'profile_id' => $target->id,
            'contact_name' => 'Primary',
            'phone_number' => '9876501234',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->subscribeUser($sender, 'silver_male');

        DB::table('profile_visibility_settings')->updateOrInsert(
            ['profile_id' => $target->id],
            [
                'visibility_scope' => 'public',
                'show_photo_to' => 'all',
                'show_contact_to' => 'accepted_interest',
                'hide_from_blocked_users' => true,
                'updated_at' => now(),
            ]
        );

        Interest::create([
            'sender_profile_id' => $senderProfile->id,
            'receiver_profile_id' => $target->id,
            'status' => 'accepted',
        ]);

        $visibility = DB::table('profile_visibility_settings')->where('profile_id', $target->id)->first();

        $access = app(ContactAccessService::class);
        try {
            $access->consumePaidContactReveal($sender, $target->fresh(), $visibility);
            $this->fail('Expected InvalidArgumentException for missing matchmaking interested.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(__('contact_access.matchmaking_interested_required'), $e->getMessage());
        }

        $mr = app(MediationRequestService::class)->createFromProfile($sender, $target->fresh());
        app(MediationRequestService::class)->respond($receiver, $mr, 'interested');

        $revealed = $access->consumePaidContactReveal($sender, $target->fresh(), $visibility);
        $this->assertSame('9876501234', $revealed['phone']);
    }

    public function test_mediation_model_fills_profile_ids_when_omitted_on_create(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        $senderProfile = MatrimonyProfile::factory()->for($sender)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $target = MatrimonyProfile::factory()->for($receiver)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $mr = MediationRequest::query()->create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'subject_profile_id' => $target->id,
            'status' => MediationRequest::STATUS_PENDING,
            'meta' => ['initiated_from' => 'test'],
            'admin_notified_at' => now(),
        ]);

        $this->assertSame($senderProfile->id, (int) $mr->sender_profile_id);
        $this->assertSame($target->id, (int) $mr->receiver_profile_id);
    }

    public function test_mediation_model_cannot_save_without_sender_profile(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        MatrimonyProfile::factory()->for($receiver)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $target = MatrimonyProfile::query()->where('user_id', $receiver->id)->firstOrFail();

        $this->expectException(LogicException::class);

        MediationRequest::query()->create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'subject_profile_id' => $target->id,
            'status' => MediationRequest::STATUS_PENDING,
            'meta' => [],
            'admin_notified_at' => now(),
        ]);
    }
}
