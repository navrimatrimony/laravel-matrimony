<?php

namespace App\Services\Showcase;

/**
 * Structured bulk-create outcome for admin UI (Step 2 — clear warnings & counts).
 */
final class ShowcaseBulkCreateReport
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public function __construct(
        public readonly int $requested,
    ) {}

    public function add(ShowcaseProfileCreateResult $result): void
    {
        $this->entries[] = [
            'profile_id' => $result->profileId,
            'outcome' => $result->outcome,
            'photo_skip_reason' => $result->photoSkipReason,
            'photo_category_label' => $result->photoCategoryLabel,
            'expected_photo_folder' => $result->expectedPhotoFolder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        $created = 0;
        $withPhoto = 0;
        $withoutPhoto = 0;
        $skippedPhoto = 0;
        $skippedLocation = 0;
        $skippedDuplicate = 0;
        $otherSkipped = 0;

        foreach ($this->entries as $entry) {
            $outcome = (string) ($entry['outcome'] ?? '');
            switch ($outcome) {
                case ShowcaseProfileCreateResult::OUTCOME_CREATED:
                    $created++;
                    $withPhoto++;
                    break;
                case ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO:
                    $created++;
                    $withoutPhoto++;
                    break;
                case ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO:
                    $skippedPhoto++;
                    break;
                case ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_LOCATION:
                    $skippedLocation++;
                    break;
                case ShowcaseProfileCreateResult::OUTCOME_SKIPPED_DUPLICATE_USER:
                    $skippedDuplicate++;
                    break;
                default:
                    $otherSkipped++;
            }
        }

        return [
            'requested' => $this->requested,
            'created' => $created,
            'with_photo' => $withPhoto,
            'without_photo' => $withoutPhoto,
            'skipped_no_photo' => $skippedPhoto,
            'skipped_no_location' => $skippedLocation,
            'skipped_duplicate_user' => $skippedDuplicate,
            'skipped_other' => $otherSkipped,
        ];
    }

    /**
     * @return list<int>
     */
    public function createdProfileIds(): array
    {
        $ids = [];
        foreach ($this->entries as $entry) {
            $id = $entry['profile_id'] ?? null;
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function profileOutcomes(): array
    {
        $rows = [];
        foreach ($this->entries as $entry) {
            $profileId = $entry['profile_id'] ?? null;
            if (! is_int($profileId) || $profileId <= 0) {
                continue;
            }
            $rows[] = [
                'profile_id' => $profileId,
                'outcome' => (string) ($entry['outcome'] ?? ''),
                'reason_key' => $entry['photo_skip_reason'] ?? null,
                'reason' => self::reasonLabel($entry['photo_skip_reason'] ?? null),
                'category' => $entry['photo_category_label'] ?? null,
                'folder' => $entry['expected_photo_folder'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Grouped photo warnings for list UI (reduces duplicate lines).
     *
     * @return list<array<string, mixed>>
     */
    public function groupedPhotoWarnings(): array
    {
        $groups = [];

        foreach ($this->entries as $entry) {
            $outcome = (string) ($entry['outcome'] ?? '');
            if (! in_array($outcome, [
                ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO,
                ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO,
            ], true)) {
                continue;
            }

            $skipped = $outcome === ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO;
            $reasonKey = (string) ($entry['photo_skip_reason'] ?? ShowcasePhotoPoolSettings::MISSING_FOLDER);
            $folder = (string) ($entry['expected_photo_folder'] ?? '');
            $category = (string) ($entry['photo_category_label'] ?? '');
            $key = ($skipped ? 'skip' : 'warn').'|'.$reasonKey.'|'.$folder;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'skipped' => $skipped,
                    'reason_key' => $reasonKey,
                    'reason' => self::reasonLabel($reasonKey),
                    'category' => $category !== '' ? $category : null,
                    'folder' => $folder !== '' ? $folder : null,
                    'count' => 0,
                    'profile_ids' => [],
                ];
            }

            $groups[$key]['count']++;
            $profileId = $entry['profile_id'] ?? null;
            if (is_int($profileId) && $profileId > 0) {
                $groups[$key]['profile_ids'][] = $profileId;
            }
        }

        $list = array_values($groups);
        usort($list, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp((string) ($a['folder'] ?? ''), (string) ($b['folder'] ?? '')));

        return $list;
    }

    public static function reasonLabel(?string $reasonKey): string
    {
        return match ($reasonKey) {
            ShowcasePhotoPoolSettings::POOL_EXHAUSTED => __('showcase_photo_pool.reason_pool_exhausted'),
            ShowcasePhotoPoolSettings::INVALID_CATEGORY => __('showcase_photo_pool.reason_invalid_category'),
            ShowcasePhotoPoolSettings::MISSING_FOLDER => __('showcase_photo_pool.reason_missing_folder'),
            default => __('showcase_bulk.reason_other'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function photoPolicyLabels(): array
    {
        $policy = ShowcasePhotoPoolSettings::policy();

        return [
            'missing' => self::actionLabel((string) ($policy['missing_exact_folder_action'] ?? '')),
            'exhausted' => self::actionLabel((string) ($policy['pool_exhausted_action'] ?? '')),
            'reuse' => ($policy['allow_reuse_when_bucket_exhausted'] ?? false)
                ? __('showcase_bulk.policy_reuse_on')
                : __('showcase_bulk.policy_reuse_off'),
        ];
    }

    private static function actionLabel(string $action): string
    {
        return $action === ShowcasePhotoPoolSettings::ACTION_SKIP_PROFILE
            ? __('showcase_bulk.policy_skip')
            : __('showcase_bulk.policy_create_without_photo');
    }
}
