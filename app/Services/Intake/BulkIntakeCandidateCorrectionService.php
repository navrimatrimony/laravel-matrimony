<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Ocr\OcrNormalize;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BulkIntakeCandidateCorrectionService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.65;

    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeCandidateMobileCollector $mobileCollector,
        private readonly IntakeHumanReviewSnapshotService $reviewSnapshotService,
        private readonly IntakePipelineService $intakePipeline,
        private readonly BulkIntakeBatchService $batchService,
    ) {}

    /**
     * @return array{
     *     intake: BiodataIntake|null,
     *     fields: list<array<string, mixed>>,
     *     source_snapshot_source: string,
     *     source_text: string|null,
     *     source_text_label: string,
     *     image_preview: array{available: bool, url: string|null, data_uri: string|null, label: string|null, message: string|null},
     *     can_save: bool
     * }
     */
    public function correctionDataForItem(BulkIntakeBatchItem $item): array
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return [
                'intake' => null,
                'fields' => [],
                'source_snapshot_source' => 'missing_intake',
                'source_text' => null,
                'source_text_label' => 'Missing linked intake',
                'image_preview' => $this->emptyImagePreview('Missing linked intake.'),
                'can_save' => false,
            ];
        }

        $source = $this->sourceSnapshot($intake);
        $snapshot = $source['snapshot'];
        $candidate = $this->candidateDisplayService->candidateForIntake($intake);
        $mobileDisplay = $this->mobileCollector->displayFromSources(
            $snapshot,
            $this->stringOrNull($intake->last_parse_input_text) ?? $this->stringOrNull($intake->raw_ocr_text)
        ) ?? $this->snapshotText($snapshot, [
            'core.primary_contact_number',
            'core.mobile',
            'primary_contact_number',
            'mobile',
        ]) ?? $candidate['mobile'];
        $fields = [
            $this->field('name', 'Name', $this->snapshotText($snapshot, ['core.full_name']) ?? $candidate['full_name'], ['full_name', 'core.full_name'], 'text'),
            $this->field('mobile', 'Mobile', $mobileDisplay, ['primary_contact_number', 'core.primary_contact_number', 'phone_number', 'contacts.0.phone_number'], 'tel'),
            $this->field('date_of_birth', 'DOB', $this->snapshotText($snapshot, ['core.date_of_birth', 'date_of_birth']) ?? $candidate['date_of_birth'], ['date_of_birth', 'core.date_of_birth'], 'text'),
            $this->field('height', 'Height', $this->snapshotHeight($snapshot) ?? $candidate['height'], ['height_cm', 'core.height_cm', 'height', 'core.height'], 'text'),
            $this->field('gender', 'Gender', $this->snapshotGender($snapshot) ?? $candidate['gender'], ['gender', 'core.gender', 'gender_id', 'core.gender_id'], 'select'),
            $this->field('education', 'Education', $this->snapshotText($snapshot, ['core.highest_education', 'core.highest_education_other', 'highest_education', 'education']) ?? $candidate['education'], ['highest_education', 'core.highest_education'], 'text'),
            $this->field('location', 'Location', $this->snapshotLocation($snapshot) ?? $candidate['city'], ['city_text', 'core.city_text', 'city', 'core.city', 'address_line', 'core.address_line'], 'text'),
        ];

        $fieldConfidence = is_array($intake->field_confidence_json) ? $intake->field_confidence_json : [];
        $parsedConfidenceMap = is_array(data_get($intake->parsed_json, 'confidence_map'))
            ? data_get($intake->parsed_json, 'confidence_map')
            : [];

        $fields = array_map(function (array $field) use ($fieldConfidence, $parsedConfidenceMap): array {
            $field['confidence'] = $this->confidenceSignal($fieldConfidence, $parsedConfidenceMap, $field['confidence_aliases']);
            $field['warnings'] = $this->warningsForField((string) $field['key'], $field['value'] ?? null);
            unset($field['confidence_aliases']);

            return $field;
        }, $fields);

        $sourceText = $this->sourceText($item, $intake);

        return [
            'intake' => $intake,
            'fields' => $fields,
            'correction_profile' => app(BulkIntakeRegistrationFormBridgeService::class)->profileFromSnapshot(
                $this->intakePipeline->normalizeBulkCandidateCorrectionSnapshot($snapshot, null),
                $item,
            ),
            'source_snapshot_source' => $source['source'],
            'source_text' => $sourceText['text'],
            'source_text_label' => $sourceText['label'],
            'image_preview' => $this->imagePreview($item, $intake),
            'can_save' => ! $this->snapshotLocked($intake),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveCorrection(BulkIntakeBatchItem $item, User $actor, array $input): BiodataIntake
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'candidate' => 'Linked biodata intake is missing.',
            ]);
        }

        if ($this->snapshotLocked($intake)) {
            throw ValidationException::withMessages([
                'candidate' => 'Candidate correction is blocked after approval or lock.',
            ]);
        }

        $source = $this->sourceSnapshot($intake);
        $snapshot = $this->applyCorrection($source['snapshot'], $input);
        $snapshot = $this->intakePipeline->normalizeBulkCandidateCorrectionSnapshot($snapshot, (int) $actor->id);

        $intake = $this->reviewSnapshotService->saveReviewedSnapshot($intake, $snapshot, [
            'reviewed_by_user_id' => (int) $actor->id,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_ADMIN,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_ADMIN_PANEL,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2A_HUMAN_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        $this->clearOperationalReviewFlagsAfterAdminCorrection($item, $actor);

        return $intake;
    }

    private function clearOperationalReviewFlagsAfterAdminCorrection(BulkIntakeBatchItem $item, User $actor): void
    {
        $item->refresh();

        if ((string) $item->item_status === BulkIntakeBatchItem::STATUS_NEEDS_REVIEW) {
            $this->batchService->clearItemNeedsReview($item, $actor);
            $item->refresh();
        }

        if (filled($item->failure_code) || filled($item->failure_message)) {
            $item->forceFill([
                'failure_code' => null,
                'failure_message' => null,
            ])->save();
        }
    }

    private function intakeForItem(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $item->loadMissing([
            'biodataIntake' => fn ($query) => $query->select([
                'id',
                'uploaded_by',
                'matrimony_profile_id',
                'original_filename',
                'file_path',
                'intake_status',
                'parse_status',
                'last_error',
                'approved_by_user',
                'approved_at',
                'intake_locked',
                'parsed_json',
                'approval_snapshot_json',
                'raw_ocr_text',
                'last_parse_input_text',
                'field_resolution_json',
                'field_confidence_json',
                'reviewed_by_user_id',
                'review_actor_type',
                'review_surface',
                'reviewed_at',
                'approval_policy',
                'approval_status',
                'created_at',
            ]),
        ]);

        return $item->biodataIntake;
    }

    /**
     * @return array{source: string, snapshot: array<string, mixed>}
     */
    private function sourceSnapshot(BiodataIntake $intake): array
    {
        if (is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== []) {
            return [
                'source' => 'approval_snapshot_json',
                'snapshot' => $intake->approval_snapshot_json,
            ];
        }

        if (is_array($intake->parsed_json) && $intake->parsed_json !== []) {
            return [
                'source' => 'parsed_json',
                'snapshot' => $intake->parsed_json,
            ];
        }

        return [
            'source' => 'empty',
            'snapshot' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function applyCorrection(array $snapshot, array $input): array
    {
        $snapshot['core'] = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $snapshot['core']['full_name'] = $this->nullableText($input['name'] ?? null);
        $mobiles = $this->normalizedMobiles($input['mobile'] ?? null);
        $snapshot['core']['primary_contact_number'] = $mobiles[0] ?? null;
        if ($mobiles !== []) {
            $snapshot['core']['all_contact_numbers'] = $mobiles;
        } elseif (array_key_exists('all_contact_numbers', $snapshot['core'])) {
            unset($snapshot['core']['all_contact_numbers']);
        }
        $snapshot['core']['date_of_birth'] = $this->normalizedDate($input['date_of_birth'] ?? null);
        $heightText = $this->stringOrNull($input['height'] ?? null);
        $heightCm = $this->normalizedHeightCm($heightText, $heightText === null ? ($input['height_cm'] ?? null) : null);
        $snapshot['core']['height_cm'] = $heightCm;
        $snapshot['core']['height'] = $heightCm !== null ? $this->heightDisplay($heightCm) : null;
        $snapshot['core']['gender'] = $this->normalizedGender($input['gender'] ?? null);
        $snapshot['core']['highest_education'] = $this->nullableText($input['education'] ?? null);
        $location = $this->correctionLocation($input);
        $snapshot['core']['city_text'] = $location;
        if (array_key_exists('location_display', $snapshot['core'])) {
            $snapshot['core']['location_display'] = $location;
        }
        if (array_key_exists('address_line', $snapshot['core'])) {
            $snapshot['core']['address_line'] = $location;
        }

        $this->applyCommunityAndOccupationCorrection($snapshot, $input);

        return $this->applyPrimaryContact($snapshot, $mobiles[0] ?? null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyCommunityAndOccupationCorrection(array &$snapshot, array $input): void
    {
        foreach ([
            'religion_id',
            'caste_id',
            'sub_caste_id',
            'occupation_master_id',
            'occupation_custom_id',
            'working_with_type_id',
            'profession_id',
            'location_id',
        ] as $idKey) {
            if (! array_key_exists($idKey, $input)) {
                continue;
            }
            $value = $input[$idKey];
            if ($value === null || $value === '') {
                unset($snapshot['core'][$idKey]);

                continue;
            }
            if (is_numeric($value)) {
                $snapshot['core'][$idKey] = (int) $value;
            }
        }

        $occupationTitle = $this->nullableText($input['occupation_title'] ?? $input['occupation'] ?? null);
        if ($occupationTitle !== null) {
            $snapshot['core']['occupation_title'] = $occupationTitle;
            $snapshot['core']['occupation'] = $occupationTitle;
        }

        $companyName = $this->nullableText($input['company_name'] ?? null);
        if ($companyName !== null) {
            $snapshot['core']['company_name'] = $companyName;
        }

        $occupationMasterId = $this->intOrNull($snapshot['core']['occupation_master_id'] ?? null);
        if ($occupationMasterId !== null) {
            $occupationMaster = \App\Models\OccupationMaster::query()->find($occupationMasterId);
            if ($occupationMaster !== null) {
                $label = trim((string) ($occupationMaster->name_mr ?: $occupationMaster->name ?: ''));
                if ($label !== '') {
                    $snapshot['core']['occupation_title'] = $label;
                    $snapshot['core']['occupation'] = $label;
                }
            }
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function applyPrimaryContact(array $snapshot, ?string $mobile): array
    {
        $contacts = is_array($snapshot['contacts'] ?? null) ? array_values($snapshot['contacts']) : [];
        $targetIndex = null;
        foreach ($contacts as $index => $contact) {
            if (! is_array($contact)) {
                continue;
            }
            if (! empty($contact['is_primary']) || in_array((string) ($contact['relation_type'] ?? ''), ['self', 'candidate'], true)) {
                $targetIndex = $index;
                break;
            }
        }

        if ($targetIndex === null) {
            if ($mobile === null) {
                $snapshot['contacts'] = $contacts;

                return $snapshot;
            }
            $contacts[] = [
                'phone_number' => $mobile,
                'relation_type' => 'self',
                'contact_name' => 'Self',
                'is_primary' => 1,
            ];
        } else {
            $contact = is_array($contacts[$targetIndex]) ? $contacts[$targetIndex] : [];
            $contact['phone_number'] = $mobile;
            $contact['relation_type'] = $contact['relation_type'] ?? 'self';
            $contact['contact_name'] = $contact['contact_name'] ?? 'Self';
            $contact['is_primary'] = $contact['is_primary'] ?? 1;
            $contacts[$targetIndex] = $contact;
        }

        $snapshot['contacts'] = $contacts;

        return $snapshot;
    }

    /**
     * @param  list<string>  $confidenceAliases
     * @return array<string, mixed>
     */
    private function field(string $key, string $label, mixed $value, array $confidenceAliases, string $type): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $this->stringOrNull($value) ?? '',
            'type' => $type,
            'confidence_aliases' => $confidenceAliases,
        ];
    }

    /**
     * @return list<string>
     */
    private function warningsForField(string $key, mixed $value): array
    {
        return match ($key) {
            'mobile' => $this->mobileWarnings($value),
            'date_of_birth' => $this->dateOfBirthWarnings($value),
            'height' => $this->heightWarnings($value),
            'gender' => $this->genderWarnings($value),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function mobileWarnings(mixed $value): array
    {
        $text = $this->stringOrNull($value);
        if ($text === null) {
            return [];
        }

        $mobiles = $this->mobileCollector->parseInput($text);
        if ($mobiles !== []) {
            return [];
        }

        return preg_match('/\d/', $text) === 1
            ? ['Mobile does not normalize to valid 10 digit Indian number(s).']
            : [];
    }

    /**
     * @return list<string>
     */
    private function dateOfBirthWarnings(mixed $value): array
    {
        $text = $this->stringOrNull($value);
        if ($text === null) {
            return [];
        }

        $normalized = OcrNormalize::normalizeDate($text);
        if (! is_string($normalized) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) !== 1) {
            return ['DOB is not parseable as YYYY-MM-DD or DD/MM/YYYY.'];
        }

        [$year, $month, $day] = array_map('intval', explode('-', $normalized));
        if (! checkdate($month, $day, $year)) {
            return ['DOB is not a valid calendar date.'];
        }

        $age = Carbon::create($year, $month, $day)->age;
        if ($age < 18) {
            return ['Age is below 18 and should be reviewed.'];
        }
        if ($age > 75) {
            return ['Age is above 75 and should be reviewed.'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function heightWarnings(mixed $value): array
    {
        $text = $this->stringOrNull($value);
        if ($text === null) {
            return [];
        }

        try {
            $this->normalizedHeightCm($text);

            return [];
        } catch (ValidationException $exception) {
            return $this->firstValidationMessages($exception, 'height', 'Height is not parseable as cm or feet/inches.');
        }
    }

    /**
     * @return list<string>
     */
    private function genderWarnings(mixed $value): array
    {
        $text = $this->stringOrNull($value);
        if ($text === null || strtolower($text) === 'unknown') {
            return ['Gender is unknown and should be reviewed.'];
        }

        try {
            $this->normalizedGender($text);

            return [];
        } catch (ValidationException $exception) {
            return $this->firstValidationMessages($exception, 'gender', 'Gender is not Male, Female, or Unknown.');
        }
    }

    /**
     * @return list<string>
     */
    private function firstValidationMessages(ValidationException $exception, string $key, string $fallback): array
    {
        $messages = $exception->errors()[$key] ?? [];
        $messages = array_values(array_filter(array_map(
            fn (mixed $message): ?string => $this->stringOrNull($message),
            is_array($messages) ? $messages : []
        )));

        return $messages !== [] ? $messages : [$fallback];
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     * @param  array<string, mixed>  $parsedConfidenceMap
     * @param  list<string>  $aliases
     * @return array{available: bool, is_low: bool, score: float|null, label: string|null}
     */
    private function confidenceSignal(array $fieldConfidence, array $parsedConfidenceMap, array $aliases): array
    {
        foreach ($aliases as $alias) {
            $signal = $this->arrayValueForPath($fieldConfidence, $alias);
            if (is_array($signal)) {
                return $this->formatConfidenceSignal($signal);
            }
        }

        foreach ($fieldConfidence as $key => $signal) {
            if (! is_array($signal)) {
                continue;
            }
            $sourcePath = $this->stringOrNull($signal['source_path'] ?? null);
            if ($sourcePath !== null && in_array($sourcePath, $aliases, true)) {
                return $this->formatConfidenceSignal($signal);
            }
            if (is_string($key) && in_array($key, $aliases, true)) {
                return $this->formatConfidenceSignal($signal);
            }
        }

        foreach ($aliases as $alias) {
            $score = $this->arrayValueForPath($parsedConfidenceMap, $alias);
            if (is_numeric($score)) {
                $score = (float) $score;

                return [
                    'available' => true,
                    'is_low' => $score < self::LOW_CONFIDENCE_THRESHOLD,
                    'score' => $score,
                    'label' => ((int) round($score * 100)).'%',
                ];
            }
        }

        return [
            'available' => false,
            'is_low' => false,
            'score' => null,
            'label' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array{available: bool, is_low: bool, score: float|null, label: string|null}
     */
    private function formatConfidenceSignal(array $signal): array
    {
        $score = is_numeric($signal['score'] ?? null) ? (float) $signal['score'] : null;
        $isLow = ! empty($signal['is_low'])
            || ($score !== null && $score < self::LOW_CONFIDENCE_THRESHOLD)
            || in_array((string) ($signal['status'] ?? ''), ['low_confidence', 'missing'], true);

        return [
            'available' => true,
            'is_low' => $isLow,
            'score' => $score,
            'label' => $score !== null ? ((int) round($score * 100)).'%' : ($isLow ? 'Low' : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function arrayValueForPath(array $source, string $path): mixed
    {
        if (array_key_exists($path, $source)) {
            return $source[$path];
        }

        return data_get($source, $path);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $paths
     */
    private function snapshotText(array $snapshot, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->stringOrNull(data_get($snapshot, $path));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHeight(array $snapshot): ?string
    {
        $heightText = $this->snapshotText($snapshot, ['core.height', 'height']);
        if ($heightText !== null) {
            return $heightText;
        }

        $heightCm = data_get($snapshot, 'core.height_cm', data_get($snapshot, 'height_cm'));
        if (is_numeric($heightCm)) {
            return ((string) (int) round((float) $heightCm)).' cm';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotGender(array $snapshot): ?string
    {
        $gender = $this->snapshotText($snapshot, ['core.gender', 'gender']);
        if ($gender === null) {
            return null;
        }

        $lower = strtolower($gender);

        return in_array($lower, ['male', 'female', 'unknown'], true) ? $lower : $gender;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotLocation(array $snapshot): ?string
    {
        return $this->snapshotText($snapshot, [
            'core.city_text',
            'core.city',
            'core.location_display',
            'core.address_line',
            'city_text',
            'city',
            'location_display',
        ]);
    }

    /**
     * @return array{text: string|null, label: string}
     */
    private function sourceText(BulkIntakeBatchItem $item, BiodataIntake $intake): array
    {
        foreach ([
            'Last parse input text' => $intake->last_parse_input_text,
            'Raw OCR text' => $intake->raw_ocr_text,
            'Bulk item text summary' => $item->summary_text,
        ] as $label => $value) {
            $text = $this->stringOrNull($value);
            if ($text !== null) {
                return [
                    'text' => $text,
                    'label' => $label,
                ];
            }
        }

        return [
            'text' => null,
            'label' => 'No parse input text available',
        ];
    }

    public function evidenceImageResponse(BulkIntakeBatchItem $item): ?\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return null;
        }

        $relativePath = $this->evidenceImagePath($item, $intake);
        if ($relativePath === null) {
            return null;
        }

        $absolutePath = Storage::disk('local')->path($relativePath);
        $mime = @mime_content_type($absolutePath);

        return response()->file($absolutePath, is_string($mime) ? [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ] : [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * @return array{available: bool, url: string|null, data_uri: string|null, label: string|null, message: string|null}
     */
    private function imagePreview(BulkIntakeBatchItem $item, BiodataIntake $intake): array
    {
        $relativePath = $this->evidenceImagePath($item, $intake);
        if ($relativePath === null) {
            return $this->emptyImagePreview('No browser-previewable image is linked to this item.');
        }

        if (! Storage::disk('local')->exists($relativePath)) {
            return $this->emptyImagePreview('Original image file is not available on local storage.');
        }

        return [
            'available' => true,
            'url' => route('admin.bulk-intakes.items.evidence-image', [
                'bulkIntakeBatch' => $item->bulk_intake_batch_id,
                'bulkIntakeBatchItem' => $item->id,
            ]),
            'data_uri' => null,
            'label' => $intake->original_filename ?: $item->original_filename ?: basename($relativePath),
            'message' => null,
        ];
    }

    private function evidenceImagePath(BulkIntakeBatchItem $item, BiodataIntake $intake): ?string
    {
        $relativePath = $this->stringOrNull($intake->file_path) ?? $this->stringOrNull($item->source_file_path);
        if ($relativePath === null) {
            return null;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return null;
        }

        return $relativePath;
    }

    /**
     * @return array{available: bool, url: string|null, data_uri: string|null, label: string|null, message: string|null}
     */
    private function emptyImagePreview(string $message): array
    {
        return [
            'available' => false,
            'url' => null,
            'data_uri' => null,
            'label' => null,
            'message' => $message,
        ];
    }

    private function snapshotLocked(BiodataIntake $intake): bool
    {
        return (bool) $intake->approved_by_user
            || (bool) $intake->intake_locked
            || $intake->approved_at !== null
            || (string) ($intake->approval_status ?? '') === IntakeHumanReviewSnapshotService::STATUS_APPROVED;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = $this->stringOrNull($value);

        return $text !== null ? mb_substr($text, 0, 255, 'UTF-8') : null;
    }

    /**
     * @return list<string>
     */
    private function normalizedMobiles(mixed $value): array
    {
        $text = $this->stringOrNull($value);
        if ($text === null) {
            return [];
        }

        $mobiles = $this->mobileCollector->parseInput($text);
        if ($mobiles === [] && preg_match('/\d/', $text) === 1) {
            throw ValidationException::withMessages([
                'mobile' => 'Enter valid 10 digit mobile number(s), comma-separated if more than one.',
            ]);
        }

        return $mobiles;
    }

    private function normalizedDate(mixed $value): ?string
    {
        $text = $this->stringOrNull($value);
        if ($text === null) {
            return null;
        }

        $normalized = OcrNormalize::normalizeDate($text);
        if (! is_string($normalized) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'date_of_birth' => 'Enter DOB as YYYY-MM-DD or DD/MM/YYYY.',
            ]);
        }

        [$year, $month, $day] = array_map('intval', explode('-', $normalized));
        if (! checkdate($month, $day, $year)) {
            throw ValidationException::withMessages([
                'date_of_birth' => 'Enter a valid DOB.',
            ]);
        }

        return $normalized;
    }

    private function normalizedHeightCm(mixed $value, mixed $heightCmValue = null): ?int
    {
        $text = $this->stringOrNull($value);
        if ($text === null && $heightCmValue !== null) {
            $heightCm = is_numeric($heightCmValue) ? (float) $heightCmValue : null;
            if ($heightCm !== null) {
                return $this->boundedHeight($heightCm);
            }
        }

        if ($text === null) {
            return null;
        }

        $text = OcrNormalize::normalizeDigits($text);
        if (preg_match('/^\s*(\d{3})(?:\.\d+)?\s*(?:cm|cms|centimeter|centimeters)?\s*$/i', $text, $m) === 1) {
            return $this->boundedHeight((float) $m[1]);
        }

        if (preg_match('/^\s*([3-7])\s*(?:[\'’′]|ft\.?|feet|foot)\s*([0-9]{1,2})?\s*(?:"|”|″|in\.?|inch|inches)?\s*$/iu', $text, $m) === 1) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            if ($inches > 11) {
                throw ValidationException::withMessages([
                    'height' => 'Enter height as cm or feet/inches.',
                ]);
            }

            return $this->boundedHeight(($feet * 12 + $inches) * 2.54);
        }

        $normalized = OcrNormalize::normalizeHeight($text);
        if (is_string($normalized) && preg_match('/([3-7])\s*[\'’′]\s*([0-9]{1,2})\s*(?:"|”|″)?/u', $normalized, $m) === 1) {
            if ((int) $m[2] > 11) {
                throw ValidationException::withMessages([
                    'height' => 'Enter height as cm or feet/inches.',
                ]);
            }

            return $this->boundedHeight((((int) $m[1]) * 12 + (int) $m[2]) * 2.54);
        }

        throw ValidationException::withMessages([
            'height' => 'Enter height as cm or feet/inches.',
        ]);
    }

    private function boundedHeight(float $heightCm): int
    {
        $height = (int) round($heightCm);
        if ($height < 120 || $height > 220) {
            throw ValidationException::withMessages([
                'height' => 'Height must be between 120 cm and 220 cm.',
            ]);
        }

        return $height;
    }

    private function heightDisplay(int $heightCm): string
    {
        $totalInches = (int) round($heightCm / 2.54);
        $feet = intdiv($totalInches, 12);
        $inches = $totalInches % 12;

        return $feet.' ft '.$inches.' in';
    }

    private function normalizedGender(mixed $value): ?string
    {
        $text = $this->stringOrNull($value);
        if ($text === null || strtolower($text) === 'unknown') {
            return null;
        }

        $normalized = OcrNormalize::normalizeGender($text);
        $lower = strtolower((string) $normalized);
        if (! in_array($lower, ['male', 'female'], true)) {
            throw ValidationException::withMessages([
                'gender' => 'Select Male, Female, or Unknown.',
            ]);
        }

        return $lower;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function correctionLocation(array $input): ?string
    {
        foreach (['location', 'location_input'] as $key) {
            $text = $this->nullableText($input[$key] ?? null);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
