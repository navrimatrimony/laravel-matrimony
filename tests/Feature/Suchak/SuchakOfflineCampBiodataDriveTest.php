<?php

namespace Tests\Feature\Suchak;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakOfflineCamp;
use App\Models\SuchakOfflineCampConversionReport;
use App\Models\SuchakOfflineCampIntakeLink;
use App\Models\SuchakOfflineCampPackageAssignment;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerLifecycleService;
use App\Modules\Suchak\Services\SuchakOfflineCampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakOfflineCampBiodataDriveTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_57_offline_camp_bulk_intake_uses_governed_pipeline_and_hash_only_duplicate_detection(): void
    {
        Bus::fake();

        foreach ([
            'suchak_offline_camps',
            'suchak_offline_camp_intake_links',
            'suchak_offline_camp_package_assignments',
            'suchak_offline_camp_conversion_reports',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        foreach ([
            'offline_camp_id',
            'source_link_id',
            'biodata_intake_id',
            'source_tag',
            'duplicate_check_status',
            'privacy_safe_duplicate_hash',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_offline_camp_intake_links', $column), $column);
        }

        foreach ([
            'matrimony_profile_id',
            'profile_payload_json',
            'candidate_mobile',
            'candidate_email',
            'phone',
            'upi',
            'deleted_at',
        ] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_offline_camps', $forbiddenColumn), $forbiddenColumn);
            $this->assertFalse(Schema::hasColumn('suchak_offline_camp_intake_links', $forbiddenColumn), $forbiddenColumn);
            $this->assertFalse(Schema::hasColumn('suchak_offline_camp_package_assignments', $forbiddenColumn), $forbiddenColumn);
        }

        [$suchakUser, $account] = $this->verifiedSuchakActor();

        $this->actingAs($suchakUser)
            ->post(route('suchak.offline-camps.store'), [
                'camp_name' => 'Day57 Community Biodata Drive',
                'camp_type' => SuchakOfflineCamp::TYPE_BIODATA_DRIVE,
                'source_tag' => 'day57_drive',
                'location_label' => 'Community hall',
                'camp_date' => '2026-06-20',
                'expected_intake_count' => 25,
                'privacy_note' => 'Only governed intake source links and hash-only duplicate checks are used.',
            ])
            ->assertRedirect();

        $camp = SuchakOfflineCamp::query()->firstOrFail();
        $this->assertSame($account->id, $camp->suchak_account_id);
        $this->assertSame('day57_drive', $camp->source_tag);

        foreach (['Candidate biodata from offline camp.', 'Candidate biodata from offline camp.'] as $rawText) {
            $this->actingAs($suchakUser)
                ->post(route('suchak.offline-camps.intakes.store', $camp), [
                    'raw_text' => $rawText,
                    'link_note' => 'Camp intake linked without direct profile insert.',
                ])
                ->assertRedirect(route('suchak.offline-camps.index', ['camp' => $camp->id]));
        }

        $this->assertSame(2, BiodataIntake::query()->count());
        $this->assertSame(0, MatrimonyProfile::query()->count());
        $this->assertSame(2, SuchakOfflineCampIntakeLink::query()->count());
        Bus::assertDispatched(ParseIntakeJob::class, 2);

        $campLinks = SuchakOfflineCampIntakeLink::query()->orderBy('id')->get();
        $this->assertSame(SuchakOfflineCampIntakeLink::DUPLICATE_UNIQUE, $campLinks[0]->duplicate_check_status);
        $this->assertSame(SuchakOfflineCampIntakeLink::DUPLICATE_POSSIBLE, $campLinks[1]->duplicate_check_status);
        $this->assertSame(64, strlen((string) $campLinks[0]->privacy_safe_duplicate_hash));
        $this->assertSame($campLinks[0]->privacy_safe_duplicate_hash, $campLinks[1]->privacy_safe_duplicate_hash);
        $this->assertSame($campLinks[0]->privacy_safe_duplicate_hash, $campLinks[1]->duplicate_match_reference_hash);
        $this->assertDatabaseMissing('suchak_offline_camp_intake_links', [
            'link_note' => 'Candidate biodata from offline camp.',
        ]);

        $this->actingAs($suchakUser)
            ->get(route('suchak.offline-camps.index', ['camp' => $camp->id]))
            ->assertOk()
            ->assertSee('Offline Camps', false)
            ->assertSee('Consent Pending List', false)
            ->assertSee('Possible Duplicate', false)
            ->assertDontSee('9876543210', false);

        try {
            app(SuchakOfflineCampService::class)->createCamp($account, $suchakUser, [
                'camp_name' => 'Unsafe camp',
                'camp_type' => SuchakOfflineCamp::TYPE_BIODATA_DRIVE,
                'source_tag' => 'unsafe_camp',
                'privacy_note' => 'Call candidate on 9876543210.',
            ]);
            $this->fail('Unsafe camp notes should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak offline camp records must not store private contact details.', $exception->getMessage());
        }
    }

    public function test_day_57_package_assignment_and_conversion_report_are_structured_without_profile_bulk_insert(): void
    {
        Bus::fake();

        [$suchakUser, $account] = $this->verifiedSuchakActor([
            'suchak_name' => 'Day57 Package Suchak',
        ]);
        $service = app(SuchakOfflineCampService::class);
        $camp = $service->createCamp($account, $suchakUser, [
            'camp_name' => 'Day57 Package Camp',
            'camp_type' => SuchakOfflineCamp::TYPE_OFFLINE_CAMP,
            'source_tag' => 'day57_pkg',
            'privacy_note' => 'Package assignments reference structured source links only.',
        ]);
        $campLink = $service->uploadAndLinkIntake(
            $camp,
            $suchakUser,
            null,
            'Camp customer package lead biodata.',
            'Package lead linked from camp.',
        );

        $customerContext = app(SuchakCustomerLifecycleService::class)->createFromSourceLink(
            $account,
            $suchakUser,
            $campLink->sourceLink,
            [
                'payer_name' => 'Camp family',
                'payer_relationship_to_candidate' => 'Parent',
                'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
                'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CONSENT_PENDING,
            ],
        );
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'package_name' => 'Camp Coordination Package',
            'package_description' => 'Structured camp follow-up and family coordination.',
            'price_amount' => '5000.00',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $suchakUser->id,
            'published_at' => now(),
        ]);

        $this->actingAs($suchakUser)
            ->post(route('suchak.offline-camps.intake-links.package-assignments.store', $campLink), [
                'service_package_id' => $package->id,
                'assignment_note' => 'Camp package assigned after consent follow-up review.',
            ])
            ->assertRedirect(route('suchak.offline-camps.index', ['camp' => $camp->id]));

        $assignment = SuchakOfflineCampPackageAssignment::query()->firstOrFail();
        $this->assertSame($customerContext->id, $assignment->customer_context_id);
        $this->assertSame($package->id, $assignment->service_package_id);
        $this->assertSame(SuchakOfflineCampPackageAssignment::STATUS_ASSIGNED, $assignment->assignment_status);

        $this->actingAs($suchakUser)
            ->post(route('suchak.offline-camps.conversion-reports.generate', $camp), [
                'report_note' => 'Report generated from Day-57 structured records.',
            ])
            ->assertRedirect(route('suchak.offline-camps.index', ['camp' => $camp->id]));

        $report = SuchakOfflineCampConversionReport::query()->firstOrFail();
        $this->assertSame(1, $report->total_intake_links);
        $this->assertSame(1, $report->unique_intake_links);
        $this->assertSame(0, $report->possible_duplicate_links);
        $this->assertSame(1, $report->consent_pending_count);
        $this->assertSame(1, $report->customer_context_count);
        $this->assertSame(1, $report->package_assignment_count);
        $this->assertSame(0, $report->metrics_json['direct_profile_bulk_insert_count']);
        $this->assertSame('privacy_safe_hash_only', $report->metrics_json['duplicate_detection']);
        $this->assertSame(0, MatrimonyProfile::query()->count());

        $otherAccount = SuchakAccount::factory()->create([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $otherPackage = SuchakServicePackage::query()->create([
            'suchak_account_id' => $otherAccount->id,
            'package_name' => 'Wrong Account Package',
            'package_description' => 'Should not be assignable across Suchak accounts.',
            'price_amount' => '1000.00',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => User::factory()->create()->id,
            'published_at' => now(),
        ]);

        try {
            $service->assignPackage($campLink, $otherPackage, $suchakUser, [
                'assignment_note' => 'Wrong account package assignment attempt.',
            ]);
            $this->fail('Cross-account package assignment should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak camp package assignment must use a package from the same Suchak account.', $exception->getMessage());
        }

        try {
            $report->delete();
            $this->fail('Camp conversion reports should not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak offline camp conversion reports cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(array $overrides = []): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'rejected_at' => null,
            'suspended_at' => null,
            'archived_at' => null,
        ], $overrides));

        return [$user, $account];
    }
}
