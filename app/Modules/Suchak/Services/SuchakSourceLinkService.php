<?php

namespace App\Modules\Suchak\Services;

use App\Models\BiodataIntake;
use App\Models\SuchakActivityLog;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakFeatureSuspension;
use App\Models\User;
use App\Services\Intake\IntakeCreationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SuchakSourceLinkService
{
    public function __construct(
        private readonly IntakeCreationService $intakeCreationService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakLimitService $limitService,
        private readonly SuchakQualityControlService $qualityControlService,
    ) {
    }

    public function canCreate(SuchakAccount $account): bool
    {
        return $this->accessService->canOperate($account);
    }

    public function createFromIntakeUpload(
        SuchakAccount $account,
        User $actor,
        ?UploadedFile $file,
        ?string $rawText,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakBiodataIntakeLink {
        $account->refresh();
        $this->assertCanCreate($account);
        $this->qualityControlService->assertFeatureAvailable($account, SuchakFeatureSuspension::FEATURE_UPLOAD);
        $this->limitService->assertUploadAllowed($account);

        $prepared = $this->intakeCreationService->prepare($actor->id, $file, $rawText);

        $result = DB::transaction(function () use ($account, $actor, $prepared, $file, $ipAddress, $userAgent): array {
            /** @var SuchakAccount $lockedAccount */
            $lockedAccount = SuchakAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanCreate($lockedAccount);
            $this->qualityControlService->assertFeatureAvailable($lockedAccount, SuchakFeatureSuspension::FEATURE_UPLOAD);
            $this->limitService->assertUploadAllowed($lockedAccount);

            $intake = $this->intakeCreationService->persistPrepared($actor->id, $prepared);

            $link = SuchakBiodataIntakeLink::query()->create([
                'suchak_account_id' => $lockedAccount->id,
                'biodata_intake_id' => $intake->id,
                'matrimony_profile_id' => null,
                'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
                'created_by_user_id' => $actor->id,
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $lockedAccount->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_SOURCE_LINK_CREATED,
                'target_type' => 'suchak_biodata_intake_link',
                'target_id' => $link->id,
                'matrimony_profile_id' => null,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'source' => 'verified_suchak_intake_upload',
                    'has_file' => $file !== null,
                ],
            ]);

            return [$link, $intake];
        });

        /** @var SuchakBiodataIntakeLink $link */
        [$link, $intake] = $result;
        /** @var BiodataIntake $intake */
        $this->intakeCreationService->dispatchParseIfEnabled($intake);

        return $link->load('biodataIntake');
    }

    private function assertCanCreate(SuchakAccount $account): void
    {
        $this->accessService->assertCanOperate(
            $account,
            'Only verified Suchak accounts can create biodata intake source links.',
        );
    }
}
