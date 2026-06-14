<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakRepresentationService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakLimitService $limitService,
    ) {
    }

    public function canCreate(SuchakAccount $account): bool
    {
        return $this->accessService->canOperate($account);
    }

    public function createPendingFromSourceLink(
        SuchakAccount $account,
        User $actor,
        SuchakBiodataIntakeLink $sourceLink,
        MatrimonyProfile $profile,
        string $representationMode = SuchakProfileRepresentation::MODE_UPLOADED_BY_SUCHAK,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileRepresentation {
        $account->refresh();
        $sourceLink->refresh();
        $profile->refresh();

        $this->assertCanCreate($account);
        $this->assertSourceLinkMatches($account, $actor, $sourceLink, $profile);
        $this->assertValidSourceLinkMode($representationMode);
        $this->limitService->assertActiveProfileSlotAvailable($account);

        return DB::transaction(function () use ($account, $actor, $sourceLink, $profile, $representationMode, $ipAddress, $userAgent): SuchakProfileRepresentation {
            /** @var SuchakAccount $lockedAccount */
            $lockedAccount = SuchakAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanCreate($lockedAccount);
            $this->limitService->assertActiveProfileSlotAvailable($lockedAccount);

            $duplicate = SuchakProfileRepresentation::query()
                ->where('suchak_account_id', $lockedAccount->id)
                ->where('matrimony_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new InvalidArgumentException('This Suchak already has a representation for this canonical profile.');
            }

            $representation = SuchakProfileRepresentation::query()->create([
                'suchak_account_id' => $lockedAccount->id,
                'matrimony_profile_id' => $profile->id,
                'biodata_intake_id' => $sourceLink->biodata_intake_id,
                'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
                'representation_mode' => $representationMode,
                'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                'first_uploaded_at' => $sourceLink->created_at,
                'first_identified_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $lockedAccount->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_REPRESENTATION_CREATED,
                'target_type' => 'suchak_profile_representation',
                'target_id' => $representation->id,
                'matrimony_profile_id' => $profile->id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'representation_mode' => $representationMode,
                    'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                    'source_link_id' => $sourceLink->id,
                ],
            ]);

            return $representation->load(['suchakAccount', 'matrimonyProfile', 'biodataIntake']);
        });
    }

    public function createPendingManualProfile(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileRepresentation {
        $account->refresh();
        $profile->refresh();

        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can create manual profile representations.',
            'Only verified Suchak accounts can create manual profile representations.',
        );
        $this->limitService->assertActiveProfileSlotAvailable($account);

        return DB::transaction(function () use ($account, $actor, $profile, $ipAddress, $userAgent): SuchakProfileRepresentation {
            /** @var SuchakAccount $lockedAccount */
            $lockedAccount = SuchakAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->accessService->assertOwnerCanOperate(
                $lockedAccount,
                $actor,
                'Only the owning Suchak account can create manual profile representations.',
                'Only verified Suchak accounts can create manual profile representations.',
            );
            $this->limitService->assertActiveProfileSlotAvailable($lockedAccount);

            $duplicate = SuchakProfileRepresentation::query()
                ->where('suchak_account_id', $lockedAccount->id)
                ->where('matrimony_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new InvalidArgumentException('This Suchak already has a representation for this canonical profile.');
            }

            $representation = SuchakProfileRepresentation::query()->create([
                'suchak_account_id' => $lockedAccount->id,
                'matrimony_profile_id' => $profile->id,
                'biodata_intake_id' => null,
                'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
                'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
                'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                'first_uploaded_at' => null,
                'first_identified_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $lockedAccount->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_REPRESENTATION_CREATED,
                'target_type' => 'suchak_profile_representation',
                'target_id' => $representation->id,
                'matrimony_profile_id' => $profile->id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
                    'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                    'source' => 'suchak_manual_profile_form',
                ],
            ]);

            return $representation->load(['suchakAccount', 'matrimonyProfile']);
        });
    }

    public function createPendingMatchedExistingProfile(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileRepresentation {
        $account->refresh();
        $profile->refresh();

        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can link existing profile representations.',
            'Only verified Suchak accounts can link existing profile representations.',
        );
        $this->limitService->assertActiveProfileSlotAvailable($account);

        return DB::transaction(function () use ($account, $actor, $profile, $ipAddress, $userAgent): SuchakProfileRepresentation {
            /** @var SuchakAccount $lockedAccount */
            $lockedAccount = SuchakAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->accessService->assertOwnerCanOperate(
                $lockedAccount,
                $actor,
                'Only the owning Suchak account can link existing profile representations.',
                'Only verified Suchak accounts can link existing profile representations.',
            );
            $this->limitService->assertActiveProfileSlotAvailable($lockedAccount);

            $duplicate = SuchakProfileRepresentation::query()
                ->where('suchak_account_id', $lockedAccount->id)
                ->where('matrimony_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new InvalidArgumentException('This Suchak already has a representation for this canonical profile.');
            }

            $representation = SuchakProfileRepresentation::query()->create([
                'suchak_account_id' => $lockedAccount->id,
                'matrimony_profile_id' => $profile->id,
                'biodata_intake_id' => null,
                'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
                'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
                'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                'first_uploaded_at' => null,
                'first_identified_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $lockedAccount->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_REPRESENTATION_CREATED,
                'target_type' => 'suchak_profile_representation',
                'target_id' => $representation->id,
                'matrimony_profile_id' => $profile->id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
                    'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
                    'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
                    'source' => 'suchak_manual_profile_duplicate_mobile',
                ],
            ]);

            return $representation->load(['suchakAccount', 'matrimonyProfile']);
        });
    }

    private function assertCanCreate(SuchakAccount $account): void
    {
        $this->accessService->assertCanOperate(
            $account,
            'Only verified Suchak accounts can create profile representations.',
        );
    }

    private function assertSourceLinkMatches(
        SuchakAccount $account,
        User $actor,
        SuchakBiodataIntakeLink $sourceLink,
        MatrimonyProfile $profile,
    ): void {
        if ((int) $sourceLink->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Source link does not belong to this Suchak account.');
        }

        if ((int) $sourceLink->created_by_user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Source link actor does not match the Suchak user.');
        }

        if ($sourceLink->source_status === SuchakBiodataIntakeLink::STATUS_CANCELLED) {
            throw new InvalidArgumentException('Cancelled source links cannot create profile representations.');
        }

        if ($sourceLink->matrimony_profile_id === null) {
            throw new InvalidArgumentException('Source link must reference a canonical profile before representation creation.');
        }

        if ((int) $sourceLink->matrimony_profile_id !== (int) $profile->id) {
            throw new InvalidArgumentException('Source link canonical profile does not match the requested representation profile.');
        }
    }

    private function assertValidSourceLinkMode(string $representationMode): void
    {
        if (! in_array($representationMode, [
            SuchakProfileRepresentation::MODE_UPLOADED_BY_SUCHAK,
            SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
        ], true)) {
            throw new InvalidArgumentException('Source-link representation mode is not allowed on Day-6.');
        }
    }
}
