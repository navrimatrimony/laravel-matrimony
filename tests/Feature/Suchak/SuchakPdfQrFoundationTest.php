<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakPdfQrFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_pdf_qr_foundation_tables_exist_with_day_8_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_biodata_exports'));
        $this->assertTrue(Schema::hasTable('suchak_qr_tokens'));

        foreach ([
            'suchak_account_id',
            'matrimony_profile_id',
            'representation_id',
            'export_type',
            'file_path',
            'generated_by_user_id',
            'downloaded_at',
            'shared_at',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_biodata_exports', $column), $column);
        }

        foreach ([
            'token_hash',
            'suchak_account_id',
            'matrimony_profile_id',
            'representation_id',
            'export_id',
            'expires_at',
            'scan_count',
            'last_scanned_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_qr_tokens', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_biodata_exports', 'profile_id'));
        $this->assertFalse(Schema::hasColumn('suchak_biodata_exports', 'raw_token'));
        $this->assertFalse(Schema::hasColumn('suchak_biodata_exports', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('suchak_biodata_exports', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_qr_tokens', 'profile_id'));
        $this->assertFalse(Schema::hasColumn('suchak_qr_tokens', 'raw_token'));
        $this->assertFalse(Schema::hasColumn('suchak_qr_tokens', 'deleted_at'));
    }

    public function test_valid_consent_and_active_representation_can_create_export_and_hashed_qr_token(): void
    {
        [$user, $representation] = $this->activeRepresentationFixture();

        $result = app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport(
            $representation,
            $user,
            null,
            '127.0.0.1',
            'Day-8 feature test',
        );

        $export = $result['export'];
        $qrToken = $result['qr_token'];
        $rawToken = $result['raw_qr_token'];

        $this->assertNotSame('', $rawToken);
        $this->assertSame('/r/'.$rawToken, $result['qr_url_path']);
        $this->assertSame(SuchakBiodataExport::TYPE_BIODATA_PDF, $export->export_type);
        $this->assertNull($export->file_path);
        $this->assertSame($user->id, $export->generated_by_user_id);
        $this->assertSame($representation->id, $export->representation_id);

        $this->assertNotSame($rawToken, $qrToken->token_hash);
        $this->assertSame(hash('sha256', $rawToken), $qrToken->token_hash);
        $this->assertSame($export->id, $qrToken->export_id);
        $this->assertSame(0, $qrToken->scan_count);
        $this->assertTrue($qrToken->expires_at->greaterThan(now()->addDays(29)));
        $this->assertTrue($qrToken->expires_at->lessThan(now()->addDays(31)));

        $this->assertDatabaseMissing('suchak_qr_tokens', ['token_hash' => $rawToken]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $representation->suchak_account_id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_PDF_GENERATED,
            'target_type' => 'suchak_biodata_export',
            'target_id' => $export->id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
        ]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $representation->suchak_account_id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_QR_GENERATED,
            'target_type' => 'suchak_qr_token',
            'target_id' => $qrToken->id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
        ]);
    }

    public function test_export_requires_verified_owner_suchak_actor(): void
    {
        [$user, $representation] = $this->activeRepresentationFixture();
        $otherUser = User::factory()->create();

        try {
            app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($representation, $otherUser);

            $this->fail('Non-owner Suchak actor should not create Day-8 exports.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only the representation Suchak actor can create governed PDF/QR exports.', $exception->getMessage());
        }

        SuchakAccount::query()
            ->whereKey($representation->suchak_account_id)
            ->update([
                'verification_status' => SuchakAccount::VERIFICATION_SUSPENDED,
                'suspended_at' => now(),
            ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only verified Suchak accounts can create governed PDF/QR exports.');

        app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($representation->fresh(), $user);
    }

    public function test_export_requires_active_representation_with_valid_consent(): void
    {
        [$user, $pendingRepresentation] = $this->representationFixture([
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
            'consent_valid_until' => null,
        ]);

        try {
            app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($pendingRepresentation, $user);

            $this->fail('Pending representation should not create Day-8 exports.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('PDF/QR export requires active representation with valid consent.', $exception->getMessage());
        }

        [$expiredUser, $expiredRepresentation] = $this->activeRepresentationFixture([
            'consent_valid_until' => now()->subDay(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PDF/QR export requires active representation with valid consent.');

        app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($expiredRepresentation, $expiredUser);
    }

    public function test_qr_scan_tracks_scan_and_returns_only_masked_summary(): void
    {
        [$user, $representation, $profile] = $this->activeRepresentationFixture();
        $this->insertPrivateContactFixture($profile);

        $result = app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($representation, $user);

        $scan = app(SuchakPdfQrFoundationService::class)->scanQrToken(
            $result['raw_qr_token'],
            '10.0.0.2',
            'Day-8 scanner',
        );

        $qrToken = $scan['qr_token'];
        $summaryJson = json_encode($scan['candidate_summary'], JSON_THROW_ON_ERROR);

        $this->assertSame(1, $qrToken->scan_count);
        $this->assertNotNull($qrToken->last_scanned_at);
        $this->assertStringNotContainsString('Sensitive Candidate', $summaryJson);
        $this->assertStringNotContainsString('9876543210', $summaryJson);
        $this->assertStringNotContainsString('1997-05-15', $summaryJson);
        $this->assertFalse($scan['candidate_summary']['visibility']['contact_reveal_allowed']);
        $this->assertTrue($scan['candidate_summary']['contact']['is_masked']);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_QR_SCANNED,
            'target_type' => 'suchak_qr_token',
            'target_id' => $qrToken->id,
            'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
            'matrimony_profile_id' => $profile->id,
        ]);
    }

    public function test_expired_qr_token_is_blocked_after_scan_tracking(): void
    {
        [$user, $representation] = $this->activeRepresentationFixture();

        $result = app(SuchakPdfQrFoundationService::class)->createGovernedBiodataPdfExport($representation, $user);
        SuchakQrToken::query()
            ->whereKey($result['qr_token']->id)
            ->update(['expires_at' => now()->subMinute()]);

        try {
            app(SuchakPdfQrFoundationService::class)->scanQrToken($result['raw_qr_token']);

            $this->fail('Expired QR token should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('QR token has expired.', $exception->getMessage());
        }

        $freshQrToken = $result['qr_token']->fresh();

        $this->assertSame(1, $freshQrToken->scan_count);
        $this->assertNotNull($freshQrToken->last_scanned_at);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_QR_SCANNED,
            'target_type' => 'suchak_qr_token',
            'target_id' => $freshQrToken->id,
            'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
        ]);
    }

    public function test_day_8_records_cannot_be_deleted(): void
    {
        $export = SuchakBiodataExport::factory()->create();
        $qrToken = SuchakQrToken::factory()->create();

        try {
            $export->delete();

            $this->fail('Suchak export delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak biodata export records cannot be deleted.', $exception->getMessage());
        }

        try {
            $qrToken->delete();

            $this->fail('Suchak QR token delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak QR token records cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $representationOverrides
     * @return array{0: User, 1: SuchakProfileRepresentation, 2: MatrimonyProfile}
     */
    private function activeRepresentationFixture(array $representationOverrides = []): array
    {
        return $this->representationFixture(array_merge([
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ], $representationOverrides), true);
    }

    /**
     * @param  array<string, mixed>  $representationOverrides
     * @return array{0: User, 1: SuchakProfileRepresentation, 2: MatrimonyProfile}
     */
    private function representationFixture(array $representationOverrides = [], bool $createAcceptedConsent = false): array
    {
        $user = User::factory()->create();

        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Sensitive Candidate',
            'date_of_birth' => '1997-05-15',
            'father_contact_1' => '8888888888',
            'mother_contact_1' => '7777777777',
        ]);

        $representation = SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ], $representationOverrides));

        if ($createAcceptedConsent) {
            SuchakConsent::factory()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
                'representation_id' => $representation->id,
                'consent_status' => SuchakConsent::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'used_at' => now(),
                'otp_verified_at' => now(),
                'valid_from' => now(),
                'valid_until' => $representation->consent_valid_until,
            ]);
        }

        return [$user, $representation, $profile];
    }

    private function insertPrivateContactFixture(MatrimonyProfile $profile): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Sensitive Candidate',
            'phone_number' => '9876543210',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRow['contact_relation_id'] = null;
        }

        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $contactRow['relation_type'] = 'self';
        }

        DB::table('profile_contacts')->insert($contactRow);
    }
}
