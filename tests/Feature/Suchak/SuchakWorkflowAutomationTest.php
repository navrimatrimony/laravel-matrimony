<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakWorkflowReminder;
use App\Models\SuchakWorkflowTimelineEvent;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakWorkflowAutomationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SuchakWorkflowAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_day_49_workflow_tables_are_structured_and_no_contact_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_workflow_reminders'));
        $this->assertTrue(Schema::hasTable('suchak_workflow_timeline_events'));

        foreach ([
            'suchak_account_id',
            'source_type',
            'source_id',
            'reminder_type',
            'reminder_key',
            'template_key',
            'channel',
            'provider_status',
            'reminder_status',
            'due_at',
            'generated_for_date',
            'message_copy',
            'metadata_json',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_workflow_reminders', $column), $column);
        }

        foreach (['phone', 'mobile', 'whatsapp', 'email', 'upi', 'deleted_at'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_workflow_reminders', $forbiddenColumn), $forbiddenColumn);
            $this->assertFalse(Schema::hasColumn('suchak_workflow_timeline_events', $forbiddenColumn), $forbiddenColumn);
        }
    }

    public function test_workflow_automation_generates_idempotent_safe_reminders_and_timeline(): void
    {
        $now = now()->setSeconds(0);
        $this->travelTo($now);

        [$account] = $this->workflowFixture($now);

        $service = app(SuchakWorkflowAutomationService::class);
        $firstRun = $service->generateDueReminders($account, $now);
        $secondRun = $service->generateDueReminders($account, $now);

        $this->assertCount(4, $firstRun);
        $this->assertCount(4, $secondRun);
        $this->assertSame(4, SuchakWorkflowReminder::query()->count());
        $this->assertSame(4, SuchakWorkflowTimelineEvent::query()->count());
        $this->assertSame(4, SuchakWorkflowReminder::query()->distinct('reminder_key')->count('reminder_key'));

        $types = SuchakWorkflowReminder::query()->pluck('reminder_type')->all();
        $this->assertContains(SuchakWorkflowReminder::TYPE_FOLLOW_UP, $types);
        $this->assertContains(SuchakWorkflowReminder::TYPE_PAYMENT, $types);
        $this->assertContains(SuchakWorkflowReminder::TYPE_CONSENT, $types);
        $this->assertContains(SuchakWorkflowReminder::TYPE_MEETING, $types);

        SuchakWorkflowReminder::query()->each(function (SuchakWorkflowReminder $reminder): void {
            $this->assertSame(SuchakWorkflowReminder::CHANNEL_WHATSAPP_COPY, $reminder->channel);
            $this->assertSame(SuchakWorkflowReminder::PROVIDER_PENDING_CREDENTIALS, $reminder->provider_status);
            $this->assertSame(SuchakWorkflowReminder::STATUS_PENDING, $reminder->reminder_status);
            $this->assertStringContainsString('masked-', $reminder->message_copy);
            $this->assertStringNotContainsString('9876543210', $reminder->message_copy);
            $this->assertStringNotContainsString('private@example.test', $reminder->message_copy);
            $this->assertStringNotContainsString('upi', strtolower($reminder->message_copy));
            $this->assertStringNotContainsString('Day49 Private Candidate', $reminder->message_copy);
        });

        $event = SuchakWorkflowTimelineEvent::query()->firstOrFail();

        try {
            $event->update(['event_title' => 'Changed']);
            $this->fail('Workflow timeline events must be immutable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak workflow timeline events are immutable and cannot be modified.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Workflow timeline events must not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak workflow timeline events are immutable and cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_command_and_dashboard_surface_workflow_reminders_without_private_contact(): void
    {
        $now = now()->setSeconds(0);
        $this->travelTo($now);

        [$account, $user] = $this->workflowFixture($now);

        $this->artisan('suchak:workflow-reminders', [
            '--account' => $account->id,
            '--at' => $now->toDateTimeString(),
        ])
            ->expectsOutput('Suchak workflow reminders generated: 4')
            ->expectsOutput('Provider delivery remains pending_credentials; this command creates WhatsApp copy only.')
            ->assertExitCode(0);

        $this->actingAs($user)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Workflow Reminders', false)
            ->assertSee('Payment follow-up', false)
            ->assertSee('Consent reminder', false)
            ->assertSee('Meeting reminder', false)
            ->assertSee('pending_credentials', false)
            ->assertDontSee('Day49 Private Candidate', false);
    }

    /**
     * @return array{0: SuchakAccount, 1: User}
     */
    private function workflowFixture(\Illuminate\Support\Carbon $now): array
    {
        [$religion, $caste] = $this->community();
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => $now,
        ]);
        $profile = $this->activeProfile([
            'full_name' => 'Day49 Private Candidate',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => $now,
            'consent_verified_at' => $now,
            'consent_valid_until' => $now->copy()->addDays(3),
        ]);

        SuchakProfileNote::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
            'note_text' => 'Call 9876543210 or private@example.test for follow-up.',
            'follow_up_at' => $now->copy()->subHour(),
        ]);

        SuchakLedgerEntry::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'status' => SuchakLedgerEntry::STATUS_DUE,
            'due_date' => $now->copy()->subDay()->toDateString(),
            'note' => 'Collect by UPI from private contact 9876543210.',
        ]);

        SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'consent_mobile_number' => '9876543210',
            'accepted_at' => $now,
            'used_at' => $now,
            'otp_verified_at' => $now,
            'valid_from' => $now->copy()->subMonth(),
            'valid_until' => $now->copy()->addDays(3),
        ]);

        $pipeline = SuchakPipeline::factory()->create([
            'selected_suchak_account_id' => $account->id,
            'target_matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
        ]);

        SuchakVisitConfirmation::query()->create([
            'pipeline_id' => $pipeline->id,
            'suchak_account_id' => $account->id,
            'request_id' => $pipeline->request_id,
            'representation_id' => $representation->id,
            'target_matrimony_profile_id' => $profile->id,
            'requesting_matrimony_profile_id' => $pipeline->requesting_matrimony_profile_id,
            'visit_status' => SuchakVisitConfirmation::STATUS_SCHEDULED,
            'confirmation_policy_mode' => SuchakVisitConfirmation::POLICY_USER_AND_ADMIN,
            'scheduled_for' => $now->copy()->addHours(3),
            'scheduled_by_user_id' => $user->id,
            'scheduled_at' => $now,
            'schedule_note' => 'Meet after calling 9876543210.',
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_PENDING,
            'user_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_PENDING,
            'admin_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_PENDING,
            'refund_review_status' => SuchakVisitConfirmation::REFUND_NOT_REQUESTED,
        ]);

        return [$account, $user];
    }

    /**
     * @return array{0: Religion, 1: Caste}
     */
    private function community(): array
    {
        $religion = Religion::query()->create([
            'key' => 'day49_religion_'.Religion::query()->count(),
            'label' => 'Day49 Religion',
            'label_en' => 'Day49 Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'day49_caste_'.Caste::query()->count(),
            'label' => 'Day49 Caste',
            'label_en' => 'Day49 Caste',
            'is_active' => true,
        ]);

        return [$religion, $caste];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'highest_education' => 'Graduate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $city->id]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, (int) $city->id, null, true, false);
        }

        $profile->forceFill([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ])->save();

        return $profile->fresh();
    }
}
