<?php

namespace App\Modules\Suchak\Services;

use App\Models\BiodataIntake;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakOfflineCamp;
use App\Models\SuchakOfflineCampConversionReport;
use App\Models\SuchakOfflineCampIntakeLink;
use App\Models\SuchakOfflineCampPackageAssignment;
use App\Models\SuchakServicePackage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakOfflineCampService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakSourceLinkService $sourceLinkService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardFor(SuchakAccount $account): array
    {
        return [
            'camps' => SuchakOfflineCamp::query()
                ->withCount(['intakeLinks', 'packageAssignments', 'conversionReports'])
                ->where('suchak_account_id', $account->id)
                ->latest()
                ->limit(20)
                ->get(),
            'source_links' => SuchakBiodataIntakeLink::query()
                ->with(['biodataIntake', 'customerContext'])
                ->where('suchak_account_id', $account->id)
                ->whereDoesntHave('offlineCampIntakeLink')
                ->latest()
                ->limit(30)
                ->get(),
            'packages' => SuchakServicePackage::query()
                ->where('suchak_account_id', $account->id)
                ->where('package_status', SuchakServicePackage::STATUS_PUBLISHED)
                ->latest()
                ->limit(30)
                ->get(),
            'recent_reports' => SuchakOfflineCampConversionReport::query()
                ->with('offlineCamp')
                ->where('suchak_account_id', $account->id)
                ->latest('generated_at')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCamp(SuchakAccount $account, User $actor, array $attributes): SuchakOfflineCamp
    {
        $this->assertOwnerCanOperate($account, $actor);

        $campKey = $this->slugKey($attributes['camp_key'] ?? $attributes['camp_name'] ?? null, 'Suchak offline camp key is required.');
        $campName = $this->requiredText($attributes['camp_name'] ?? null, 'Suchak offline camp name is required.', 160);
        $campNameMr = $this->nullableText($attributes['camp_name_mr'] ?? null, 160);
        $campType = $this->allowed($attributes['camp_type'] ?? SuchakOfflineCamp::TYPE_BIODATA_DRIVE, SuchakOfflineCamp::TYPES, 'Suchak offline camp type is invalid.');
        $sourceTag = $this->slugKey($attributes['source_tag'] ?? $campKey, 'Suchak offline camp source tag is required.');
        $locationLabel = $this->nullableText($attributes['location_label'] ?? null, 160);
        $locationLabelMr = $this->nullableText($attributes['location_label_mr'] ?? null, 160);
        $privacyNote = $this->requiredText($attributes['privacy_note'] ?? null, 'Suchak offline camp privacy note is required.', 1000);
        $privacyNoteMr = $this->nullableText($attributes['privacy_note_mr'] ?? null, 1000);
        $this->assertSafeOperationalText($campName.' '.($campNameMr ?? '').' '.$sourceTag.' '.($locationLabel ?? '').' '.($locationLabelMr ?? '').' '.$privacyNote.' '.($privacyNoteMr ?? ''));

        return DB::transaction(function () use ($account, $actor, $attributes, $campKey, $campName, $campNameMr, $campType, $sourceTag, $locationLabel, $locationLabelMr, $privacyNote, $privacyNoteMr): SuchakOfflineCamp {
            $camp = SuchakOfflineCamp::query()->create([
                'suchak_account_id' => $account->id,
                'camp_key' => $campKey,
                'camp_name' => $campName,
                'camp_name_mr' => $campNameMr,
                'camp_type' => $campType,
                'camp_status' => SuchakOfflineCamp::STATUS_PLANNED,
                'source_tag' => $sourceTag,
                'location_label' => $locationLabel,
                'location_label_mr' => $locationLabelMr,
                'camp_date' => $this->nullableDate($attributes['camp_date'] ?? null),
                'expected_intake_count' => $this->nullableCount($attributes['expected_intake_count'] ?? 0),
                'privacy_note' => $privacyNote,
                'privacy_note_mr' => $privacyNoteMr,
                'created_by_user_id' => $actor->id,
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => 'offline_camp_created',
                'target_type' => 'suchak_offline_camp',
                'target_id' => $camp->id,
                'metadata_json' => [
                    'camp_type' => $camp->camp_type,
                    'source_tag' => $camp->source_tag,
                ],
            ]);

            return $camp->fresh(['suchakAccount', 'createdByUser']);
        });
    }

    public function uploadAndLinkIntake(
        SuchakOfflineCamp $camp,
        User $actor,
        ?UploadedFile $file,
        ?string $rawText,
        ?string $linkNote = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakOfflineCampIntakeLink {
        $camp = $this->campForActor($camp, $actor);
        $link = $this->sourceLinkService->createFromIntakeUpload(
            $camp->suchakAccount,
            $actor,
            $file,
            $rawText,
            $ipAddress,
            $userAgent,
        );

        return $this->linkSourceLink($camp, $actor, $link, $linkNote, $ipAddress, $userAgent);
    }

    /**
     * @param  array<int, int|string>  $sourceLinkIds
     * @return Collection<int, SuchakOfflineCampIntakeLink>
     */
    public function linkExistingSourceLinks(
        SuchakOfflineCamp $camp,
        User $actor,
        array $sourceLinkIds,
        ?string $linkNote = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Collection {
        $camp = $this->campForActor($camp, $actor);
        $ids = collect($sourceLinkIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new InvalidArgumentException('At least one Suchak source link is required for camp linking.');
        }

        return DB::transaction(function () use ($camp, $actor, $ids, $linkNote, $ipAddress, $userAgent): Collection {
            return $ids
                ->map(function (int $sourceLinkId) use ($camp, $actor, $linkNote, $ipAddress, $userAgent): SuchakOfflineCampIntakeLink {
                    $sourceLink = SuchakBiodataIntakeLink::query()
                        ->whereKey($sourceLinkId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    return $this->persistCampLink($camp, $actor, $sourceLink, $linkNote, $ipAddress, $userAgent);
                })
                ->values();
        });
    }

    public function linkSourceLink(
        SuchakOfflineCamp $camp,
        User $actor,
        SuchakBiodataIntakeLink $sourceLink,
        ?string $linkNote = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakOfflineCampIntakeLink {
        $camp = $this->campForActor($camp, $actor);

        return DB::transaction(fn (): SuchakOfflineCampIntakeLink => $this->persistCampLink($camp, $actor, $sourceLink, $linkNote, $ipAddress, $userAgent));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assignPackage(
        SuchakOfflineCampIntakeLink $campLink,
        SuchakServicePackage $package,
        User $actor,
        array $attributes,
    ): SuchakOfflineCampPackageAssignment {
        $campLink->refresh()->loadMissing(['offlineCamp.suchakAccount', 'sourceLink.customerContext']);
        $account = $campLink->offlineCamp->suchakAccount;
        $this->assertOwnerCanOperate($account, $actor);

        $package->refresh();
        if ((int) $package->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Suchak camp package assignment must use a package from the same Suchak account.');
        }

        if ($package->package_status !== SuchakServicePackage::STATUS_PUBLISHED) {
            throw new InvalidArgumentException('Suchak camp package assignment requires a published package.');
        }

        $customerContext = $campLink->sourceLink->customerContext;
        if ($package->customer_context_id !== null
            && (! $customerContext instanceof SuchakCustomerContext || (int) $package->customer_context_id !== (int) $customerContext->id)) {
            throw new InvalidArgumentException('Suchak camp package assignment customer context must match the source link.');
        }

        $note = $this->requiredText($attributes['assignment_note'] ?? null, 'Suchak camp package assignment note is required.', 1000);
        $this->assertSafeOperationalText($note);

        return DB::transaction(function () use ($campLink, $package, $actor, $account, $customerContext, $note): SuchakOfflineCampPackageAssignment {
            $duplicate = SuchakOfflineCampPackageAssignment::query()
                ->where('offline_camp_intake_link_id', $campLink->id)
                ->where('service_package_id', $package->id)
                ->lockForUpdate()
                ->first();
            if ($duplicate instanceof SuchakOfflineCampPackageAssignment) {
                throw new InvalidArgumentException('This package is already assigned to the camp source link.');
            }

            $assignment = SuchakOfflineCampPackageAssignment::query()->create([
                'offline_camp_id' => $campLink->offline_camp_id,
                'offline_camp_intake_link_id' => $campLink->id,
                'suchak_account_id' => $account->id,
                'source_link_id' => $campLink->source_link_id,
                'customer_context_id' => $customerContext?->id,
                'service_package_id' => $package->id,
                'assignment_status' => SuchakOfflineCampPackageAssignment::STATUS_ASSIGNED,
                'assignment_note' => $note,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => 'offline_camp_package_assigned',
                'target_type' => 'suchak_offline_camp_package_assignment',
                'target_id' => $assignment->id,
                'metadata_json' => [
                    'offline_camp_id' => $campLink->offline_camp_id,
                    'source_link_id' => $campLink->source_link_id,
                    'service_package_id' => $package->id,
                    'customer_context_id' => $customerContext?->id,
                ],
            ]);

            return $assignment->fresh(['offlineCamp', 'intakeLink', 'sourceLink', 'customerContext', 'servicePackage']);
        });
    }

    public function generateConversionReport(
        SuchakOfflineCamp $camp,
        User $actor,
        ?string $note = null,
    ): SuchakOfflineCampConversionReport {
        $camp = $this->campForActor($camp, $actor);
        $note = $this->requiredText($note ?? 'Generated from structured Suchak camp records only.', 'Suchak camp conversion report note is required.', 1000);
        $this->assertSafeOperationalText($note);

        $camp->loadMissing(['intakeLinks.sourceLink.customerContext', 'packageAssignments']);
        $intakeLinks = $camp->intakeLinks;
        $consentPending = $this->consentPendingList($camp)->count();
        $customerContextCount = $intakeLinks
            ->filter(fn (SuchakOfflineCampIntakeLink $link): bool => $link->sourceLink?->customerContext instanceof SuchakCustomerContext)
            ->count();
        $activeServiceCount = $intakeLinks
            ->filter(fn (SuchakOfflineCampIntakeLink $link): bool => $link->sourceLink?->customerContext?->customer_lifecycle_status === SuchakCustomerContext::STATUS_ACTIVE_SERVICE)
            ->count();

        return DB::transaction(function () use ($camp, $actor, $note, $intakeLinks, $consentPending, $customerContextCount, $activeServiceCount): SuchakOfflineCampConversionReport {
            $report = SuchakOfflineCampConversionReport::query()->create([
                'offline_camp_id' => $camp->id,
                'suchak_account_id' => $camp->suchak_account_id,
                'source_tag' => $camp->source_tag,
                'report_status' => SuchakOfflineCampConversionReport::STATUS_GENERATED,
                'total_intake_links' => $intakeLinks->count(),
                'unique_intake_links' => $intakeLinks->where('duplicate_check_status', SuchakOfflineCampIntakeLink::DUPLICATE_UNIQUE)->count(),
                'possible_duplicate_links' => $intakeLinks->where('duplicate_check_status', SuchakOfflineCampIntakeLink::DUPLICATE_POSSIBLE)->count(),
                'consent_pending_count' => $consentPending,
                'customer_context_count' => $customerContextCount,
                'package_assignment_count' => $camp->packageAssignments->count(),
                'active_service_count' => $activeServiceCount,
                'report_note' => $note,
                'metrics_json' => [
                    'direct_profile_bulk_insert_count' => 0,
                    'duplicate_detection' => 'privacy_safe_hash_only',
                    'intake_pipeline' => 'biodata_intakes_and_suchak_source_links_only',
                ],
                'generated_by_user_id' => $actor->id,
                'generated_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $camp->suchak_account_id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => 'offline_camp_conversion_report_generated',
                'target_type' => 'suchak_offline_camp_conversion_report',
                'target_id' => $report->id,
                'metadata_json' => $report->metrics_json,
            ]);

            return $report->fresh(['offlineCamp', 'generatedByUser']);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function consentPendingList(SuchakOfflineCamp $camp): Collection
    {
        $camp->loadMissing(['intakeLinks.sourceLink.customerContext', 'intakeLinks.packageAssignments']);

        return $camp->intakeLinks
            ->filter(function (SuchakOfflineCampIntakeLink $campLink): bool {
                $sourceLink = $campLink->sourceLink;
                $context = $sourceLink?->customerContext;

                return $campLink->duplicate_check_status === SuchakOfflineCampIntakeLink::DUPLICATE_POSSIBLE
                    || $sourceLink?->source_status === SuchakBiodataIntakeLink::STATUS_DUPLICATE_PENDING_CONSENT
                    || ! $context instanceof SuchakCustomerContext
                    || $context->customer_lifecycle_status === SuchakCustomerContext::STATUS_CONSENT_PENDING;
            })
            ->map(function (SuchakOfflineCampIntakeLink $campLink): array {
                $sourceLink = $campLink->sourceLink;
                $context = $sourceLink?->customerContext;
                $status = 'intake_review_pending';

                if ($campLink->duplicate_check_status === SuchakOfflineCampIntakeLink::DUPLICATE_POSSIBLE
                    || $sourceLink?->source_status === SuchakBiodataIntakeLink::STATUS_DUPLICATE_PENDING_CONSENT) {
                    $status = 'duplicate_consent_review';
                } elseif ($context?->customer_lifecycle_status === SuchakCustomerContext::STATUS_CONSENT_PENDING) {
                    $status = 'consent_pending';
                }

                return [
                    'camp_link' => $campLink,
                    'source_link' => $sourceLink,
                    'customer_context' => $context,
                    'consent_status' => $status,
                    'privacy_safe_duplicate_hash' => $campLink->privacy_safe_duplicate_hash,
                ];
            })
            ->values();
    }

    private function persistCampLink(
        SuchakOfflineCamp $camp,
        User $actor,
        SuchakBiodataIntakeLink $sourceLink,
        ?string $linkNote,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakOfflineCampIntakeLink {
        $sourceLink->refresh()->loadMissing('biodataIntake');
        if ((int) $sourceLink->suchak_account_id !== (int) $camp->suchak_account_id) {
            throw new InvalidArgumentException('Suchak offline camp source link must belong to the same Suchak account.');
        }

        if ($sourceLink->source_status === SuchakBiodataIntakeLink::STATUS_CANCELLED) {
            throw new InvalidArgumentException('Cancelled Suchak source links cannot be linked to offline camps.');
        }

        if ($sourceLink->biodata_intake_id === null) {
            throw new InvalidArgumentException('Suchak offline camp source links must reference a biodata intake.');
        }

        $note = $this->nullableText($linkNote, 1000);
        $this->assertSafeOperationalText($note ?? '');

        $hash = $this->privacySafeDuplicateHash($sourceLink);
        $duplicate = $hash === null ? null : SuchakOfflineCampIntakeLink::query()
            ->where('suchak_account_id', $camp->suchak_account_id)
            ->where('privacy_safe_duplicate_hash', $hash)
            ->lockForUpdate()
            ->first();

        $campLink = SuchakOfflineCampIntakeLink::query()->create([
            'offline_camp_id' => $camp->id,
            'suchak_account_id' => $camp->suchak_account_id,
            'source_link_id' => $sourceLink->id,
            'biodata_intake_id' => $sourceLink->biodata_intake_id,
            'source_tag' => $camp->source_tag,
            'source_status_snapshot' => $sourceLink->source_status,
            'link_status' => SuchakOfflineCampIntakeLink::STATUS_LINKED,
            'duplicate_check_status' => $hash === null
                ? SuchakOfflineCampIntakeLink::DUPLICATE_UNAVAILABLE
                : ($duplicate instanceof SuchakOfflineCampIntakeLink ? SuchakOfflineCampIntakeLink::DUPLICATE_POSSIBLE : SuchakOfflineCampIntakeLink::DUPLICATE_UNIQUE),
            'privacy_safe_duplicate_hash' => $hash,
            'duplicate_match_reference_hash' => $duplicate?->privacy_safe_duplicate_hash,
            'link_note' => $note,
            'linked_by_user_id' => $actor->id,
            'linked_at' => now(),
        ]);

        $this->activityLogger->record([
            'suchak_account_id' => $camp->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => 'offline_camp_intake_linked',
            'target_type' => 'suchak_offline_camp_intake_link',
            'target_id' => $campLink->id,
            'matrimony_profile_id' => null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'offline_camp_id' => $camp->id,
                'source_tag' => $camp->source_tag,
                'source_link_id' => $sourceLink->id,
                'biodata_intake_id' => $sourceLink->biodata_intake_id,
                'duplicate_check_status' => $campLink->duplicate_check_status,
                'direct_profile_bulk_insert' => false,
            ],
        ]);

        return $campLink->fresh(['offlineCamp', 'sourceLink.biodataIntake', 'biodataIntake', 'linkedByUser']);
    }

    private function campForActor(SuchakOfflineCamp $camp, User $actor): SuchakOfflineCamp
    {
        $camp->refresh()->loadMissing('suchakAccount');
        $this->assertOwnerCanOperate($camp->suchakAccount, $actor);

        if ($camp->camp_status === SuchakOfflineCamp::STATUS_CANCELLED) {
            throw new InvalidArgumentException('Cancelled Suchak offline camps cannot be changed.');
        }

        return $camp;
    }

    private function assertOwnerCanOperate(SuchakAccount $account, User $actor): void
    {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can manage offline camps.',
            'Only verified Suchak accounts can manage offline camps.',
        );
    }

    private function privacySafeDuplicateHash(SuchakBiodataIntakeLink $sourceLink): ?string
    {
        $intake = $sourceLink->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return null;
        }

        $contentHash = trim((string) ($intake->content_hash ?? ''));
        if ($contentHash !== '') {
            return hash('sha256', 'content_hash:'.$contentHash);
        }

        $rawText = trim(preg_replace('/\s+/', ' ', mb_strtolower((string) ($intake->raw_ocr_text ?? ''))) ?? '');
        if ($rawText === '') {
            return null;
        }

        return hash('sha256', 'raw_text:'.$rawText);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowed(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function slugKey(mixed $value, string $message): string
    {
        $normalized = Str::slug(trim((string) ($value ?? '')), '_');
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, 96, '');
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, $limit, '');
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, $limit, '');
    }

    private function nullableDate(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
            throw new InvalidArgumentException('Suchak offline camp date is invalid.');
        }

        return $normalized;
    }

    private function nullableCount(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException('Suchak offline camp expected intake count is invalid.');
        }

        return (int) $value;
    }

    private function assertSafeOperationalText(string $text): void
    {
        if ($text === '') {
            return;
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $text) === 1) {
            throw new InvalidArgumentException('Suchak offline camp records must not store private contact details.');
        }

        if (preg_match('/\bupi\b|@[a-z0-9]{2,}\b/i', $text) === 1) {
            throw new InvalidArgumentException('Suchak offline camp records must not expose direct payment handles.');
        }
    }
}
