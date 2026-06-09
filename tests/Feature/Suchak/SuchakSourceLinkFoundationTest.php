<?php

namespace Tests\Feature\Suchak;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SuchakSourceLinkFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_source_link_table_exists_with_day_5_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_biodata_intake_links'));

        foreach ([
            'suchak_account_id',
            'biodata_intake_id',
            'matrimony_profile_id',
            'source_status',
            'created_by_user_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_biodata_intake_links', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_biodata_intake_links', 'profile_id'));
        $this->assertFalse(Schema::hasColumn('suchak_biodata_intake_links', 'deleted_at'));
    }

    public function test_verified_suchak_can_create_source_link_through_existing_intake_creation_path(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('suchak.intakes.create'))
            ->assertOk()
            ->assertSee('Create Suchak Intake Source Link', false);

        $response = $this->actingAs($user)
            ->post(route('suchak.intakes.store'), [
                'raw_text' => 'Candidate biodata text from verified Suchak.',
            ]);

        $intake = BiodataIntake::query()->where('uploaded_by', $user->id)->first();
        $this->assertNotNull($intake);

        $response->assertRedirect(route('intake.status', $intake));

        $this->assertSame('Candidate biodata text from verified Suchak.', $intake->raw_ocr_text);
        $this->assertSame('uploaded', $intake->intake_status);
        $this->assertSame('pending', $intake->parse_status);

        $link = SuchakBiodataIntakeLink::query()
            ->where('biodata_intake_id', $intake->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame($account->id, $link->suchak_account_id);
        $this->assertNull($link->matrimony_profile_id);
        $this->assertSame(SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED, $link->source_status);
        $this->assertSame($user->id, $link->created_by_user_id);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SOURCE_LINK_CREATED,
            'target_type' => 'suchak_biodata_intake_link',
            'target_id' => $link->id,
        ]);

        Bus::assertDispatched(ParseIntakeJob::class);
    }

    public function test_unverified_suchak_cannot_create_source_link(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.intakes.create'))
            ->assertRedirect(route('suchak.dashboard'));

        $this->actingAs($user)
            ->post(route('suchak.intakes.store'), [
                'raw_text' => 'Pending Suchak must not create source links.',
            ])
            ->assertRedirect(route('suchak.dashboard'));

        $this->assertDatabaseCount('biodata_intakes', 0);
        $this->assertDatabaseCount('suchak_biodata_intake_links', 0);

        Bus::assertNotDispatched(ParseIntakeJob::class);
    }

    public function test_suspended_suchak_cannot_create_source_link(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_SUSPENDED,
        ]);

        $this->actingAs($user)
            ->post(route('suchak.intakes.store'), [
                'raw_text' => 'Suspended Suchak must not create source links.',
            ])
            ->assertRedirect(route('suchak.dashboard'));

        $this->assertDatabaseCount('biodata_intakes', 0);
        $this->assertDatabaseCount('suchak_biodata_intake_links', 0);
    }

    public function test_normal_member_intake_upload_does_not_create_suchak_source_link(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('intake.store'), [
                'raw_text' => 'Normal member biodata text.',
            ]);

        $this->assertDatabaseCount('biodata_intakes', 1);
        $this->assertDatabaseCount('suchak_biodata_intake_links', 0);
    }

    public function test_suchak_source_links_cannot_be_deleted(): void
    {
        $link = SuchakBiodataIntakeLink::factory()->create();

        $this->expectException(RuntimeException::class);

        $link->delete();
    }
}
