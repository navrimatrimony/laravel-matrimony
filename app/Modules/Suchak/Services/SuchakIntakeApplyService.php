<?php

namespace App\Modules\Suchak\Services;

use App\Models\BiodataIntake;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\IntakeApprovalService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SuchakIntakeApplyService
{
    public function __construct(
        private readonly IntakeApprovalService $approvalService,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakRepresentationService $representationService,
        private readonly SuchakCustomerLifecycleService $customerLifecycleService,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    public function approveAndApply(
        BiodataIntake $intake,
        User $actor,
        ?array $snapshot,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $sourceLink = SuchakBiodataIntakeLink::query()
            ->where('biodata_intake_id', $intake->id)
            ->with('suchakAccount')
            ->first();

        if (! $sourceLink) {
            throw new InvalidArgumentException('Suchak source link was not found for this intake.');
        }

        return DB::transaction(function () use ($sourceLink, $intake, $actor, $snapshot, $ipAddress, $userAgent): array {
            /** @var SuchakBiodataIntakeLink $lockedLink */
            $lockedLink = SuchakBiodataIntakeLink::query()
                ->whereKey($sourceLink->id)
                ->lockForUpdate()
                ->with('suchakAccount')
                ->firstOrFail();

            $account = $lockedLink->suchakAccount;
            if (! $account || ! $this->accessService->canOwnerOperate($account, $actor)) {
                throw new InvalidArgumentException('Only the owning Suchak account can apply this intake.');
            }

            $wasLinkedBeforeApply = $lockedLink->matrimony_profile_id !== null;
            $mutationOptions = [
                // Suchak uploader is not the candidate owner; SAME_USER duplicate detection must not point at the Suchak account.
                'duplicate_detection_user_id' => null,
            ];

            if (! $wasLinkedBeforeApply) {
                $mutationOptions['new_profile_user_attributes'] = $this->candidateUserAttributes($snapshot, $intake);
            }

            $result = $this->approvalService->approve(
                $intake->fresh(),
                (int) $actor->id,
                $snapshot,
                $mutationOptions,
            );

            $profileId = isset($result['profile_id']) && $result['profile_id'] !== null
                ? (int) $result['profile_id']
                : null;

            if ($profileId !== null) {
                $status = ($result['mutation_success'] ?? false)
                    ? ($wasLinkedBeforeApply ? SuchakBiodataIntakeLink::STATUS_LINKED_TO_EXISTING_PROFILE : SuchakBiodataIntakeLink::STATUS_CREATED_NEW_PROFILE)
                    : SuchakBiodataIntakeLink::STATUS_DUPLICATE_PENDING_CONSENT;

                $lockedLink->forceFill([
                    'matrimony_profile_id' => $profileId,
                    'source_status' => $status,
                ])->save();

                if (($result['mutation_success'] ?? false) === true) {
                    $profile = MatrimonyProfile::query()->findOrFail($profileId);
                    $representation = $this->existingOrCreateRepresentation(
                        $account,
                        $actor,
                        $lockedLink->fresh(),
                        $profile,
                        $wasLinkedBeforeApply
                            ? SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE
                            : SuchakProfileRepresentation::MODE_UPLOADED_BY_SUCHAK,
                        $ipAddress,
                        $userAgent,
                    );

                    $context = $this->existingOrCreateCustomerContext(
                        $account,
                        $actor,
                        $representation,
                        $profile,
                        $ipAddress,
                        $userAgent,
                    );

                    $result['representation_id'] = $representation->id;
                    $result['customer_context_id'] = $context->id;
                }
            }

            $result['source_link_id'] = $lockedLink->id;

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    private function candidateUserAttributes(?array $snapshot, BiodataIntake $intake): array
    {
        $working = is_array($snapshot) ? $snapshot : (is_array($intake->parsed_json) ? $intake->parsed_json : []);
        $core = is_array($working['core'] ?? null) ? $working['core'] : [];

        $name = trim((string) ($core['full_name'] ?? ''));
        if ($name === '') {
            $name = 'Candidate from intake #'.$intake->id;
        }

        return [
            'name' => $name,
            'email' => null,
            'mobile' => null,
            'gender' => $this->genderKey($core),
            'registering_for' => 'other',
        ];
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function genderKey(array $core): ?string
    {
        $gender = strtolower(trim((string) ($core['gender'] ?? '')));
        if (in_array($gender, ['male', 'female'], true)) {
            return $gender;
        }

        $genderId = $core['gender_id'] ?? null;
        if ($genderId !== null && $genderId !== '' && is_numeric($genderId)) {
            $key = MasterGender::query()->whereKey((int) $genderId)->value('key');

            return is_string($key) && trim($key) !== '' ? trim($key) : null;
        }

        return null;
    }

    private function existingOrCreateRepresentation(
        SuchakAccount $account,
        User $actor,
        SuchakBiodataIntakeLink $sourceLink,
        MatrimonyProfile $profile,
        string $mode,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakProfileRepresentation {
        $existing = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->representationService->createPendingFromSourceLink(
            $account,
            $actor,
            $sourceLink,
            $profile,
            $mode,
            $ipAddress,
            $userAgent,
        );
    }

    private function existingOrCreateCustomerContext(
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
        MatrimonyProfile $profile,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakCustomerContext {
        $existing = SuchakCustomerContext::query()
            ->where('representation_id', $representation->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->customerLifecycleService->createForRepresentation(
            $account,
            $actor,
            $representation,
            [
                'source_type' => SuchakCustomerContext::SOURCE_TYPE_INTAKE_UPLOAD,
                'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
                'payer_name' => $profile->full_name,
            ],
            $ipAddress,
            $userAgent,
        );
    }
}
