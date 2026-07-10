<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MasterMotherTongue;
use App\Models\OccupationMaster;
use App\Models\Religion;
use App\Models\Caste;
use App\Models\WorkingWithType;
use App\Support\HeightDisplay;
use App\Support\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkIntakePublicRegistrationService
{
    private const TOKEN_BYTES = 32;

    /** @var list<string> */
    private const OCCUPATION_EXEMPT_SLUGS = [
        'not_working',
        'unemployed',
        'home_maker',
        'retired',
        'student',
    ];

    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeWhatsAppConsentService $whatsappConsentService,
        private readonly IntakeHumanReviewSnapshotService $reviewSnapshotService,
        private readonly IntakePipelineService $intakePipeline,
    ) {}

    public function publicUrl(BulkIntakeBatchItem $item): string
    {
        return route('bulk-intake.register.show', [
            'token' => $this->ensureToken($item),
        ]);
    }

    public function ensureToken(BulkIntakeBatchItem $item): string
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $token = trim((string) ($registration['public_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $token = Str::random(self::TOKEN_BYTES);
        $registration['public_token'] = $token;
        $registration['public_token_created_at'] = now()->toISOString();
        $meta['registration'] = $registration;
        $item->forceFill(['item_meta_json' => $meta])->save();

        return $token;
    }

    public function itemForToken(string $token): ?BulkIntakeBatchItem
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return BulkIntakeBatchItem::query()
            ->where('item_meta_json->registration->public_token', $token)
            ->first();
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public function accessGate(BulkIntakeBatchItem $item): array
    {
        if ($this->whatsappConsentService->consentStatus($item) !== BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED) {
            return ['allowed' => false, 'reason' => 'consent_not_received'];
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return ['allowed' => false, 'reason' => 'missing_intake'];
        }

        if ($this->snapshotLocked($intake)) {
            return ['allowed' => false, 'reason' => 'snapshot_locked'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @return array{
     *     item: BulkIntakeBatchItem,
     *     intake: BiodataIntake,
     *     candidate_name: string|null,
     *     fields: array<string, mixed>,
     *     height_cm: int|null,
     *     genders: list<array{id: int, label: string}>,
     *     mother_tongues: list<array{id: int, label: string}>,
     *     marital_statuses: list<array{id: int, label: string}>,
     *     religions: list<array{id: int, label: string}>,
     *     castes: list<array{id: int, label: string}>,
     *     working_with_options: list<array{id: int, slug: string, label: string}>,
     *     occupations: list<array{id: int, label: string, working_with_type_id: int|null}>,
     *     occupation_exempt_slugs: list<string>,
     *     registration_complete: bool
     * }
     */
    public function formPayload(BulkIntakeBatchItem $item): array
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $snapshot = $this->sourceSnapshot($intake);
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $heightCm = $this->heightCmFromCore($core);

        return [
            'item' => $item,
            'intake' => $intake,
            'candidate_name' => $candidate['full_name'] ?? null,
            'fields' => [
                'full_name' => $core['full_name'] ?? $candidate['full_name'] ?? null,
                'mobile' => $candidate['mobile'] ?? $core['primary_contact_number'] ?? null,
                'date_of_birth' => $core['date_of_birth'] ?? $candidate['date_of_birth'] ?? null,
                'gender' => $this->genderValue($core),
                'mother_tongue_id' => $this->resolveMotherTongueId($intake, $core),
                'marital_status_id' => $this->intOrNull($core['marital_status_id'] ?? null),
                'religion_id' => $this->intOrNull($core['religion_id'] ?? null),
                'caste_id' => $this->intOrNull($core['caste_id'] ?? null),
                'location' => $candidate['city'] ?? $core['city_text'] ?? $core['address_line'] ?? null,
                'education' => $candidate['education'] ?? $core['highest_education'] ?? null,
                'working_with_type_id' => $this->workingWithTypeIdFromCore($core),
                'occupation_master_id' => $this->occupationMasterIdFromCore($core),
                'company_name' => $this->stringOrNull($core['company_name'] ?? null),
                'annual_income' => $this->stringOrNull($core['annual_income'] ?? $core['income_amount'] ?? null),
            ],
            'height_cm' => $heightCm,
            'genders' => $this->genderOptions(),
            'mother_tongues' => $this->motherTongueOptions(),
            'marital_statuses' => $this->maritalStatusOptions(),
            'religions' => $this->religionOptions(),
            'castes' => $this->casteOptions(),
            'working_with_options' => $this->workingWithOptions(),
            'occupations' => $this->occupationOptions(),
            'occupation_exempt_slugs' => self::OCCUPATION_EXEMPT_SLUGS,
            'registration_complete' => $this->registrationComplete($item),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(BulkIntakeBatchItem $item, array $input): BiodataIntake
    {
        $gate = $this->accessGate($item);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'registration' => $this->accessDeniedMessage((string) $gate['reason']),
            ]);
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $validated = validator($input, [
            'full_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:32'],
            'date_of_birth' => ['required', 'date'],
            'height_cm' => ['required', 'integer', 'min:120', 'max:220'],
            'gender' => ['required', 'string', 'max:32'],
            'mother_tongue_id' => ['required', 'integer', 'exists:master_mother_tongues,id'],
            'marital_status_id' => ['required', 'integer', 'exists:master_marital_statuses,id'],
            'religion_id' => ['required', 'integer', 'exists:master_religions,id'],
            'caste_id' => ['required', 'integer', 'exists:master_castes,id'],
            'location' => ['required', 'string', 'max:255'],
            'education' => ['required', 'string', 'max:255'],
            'working_with_type_id' => ['required', 'integer', 'exists:working_with_types,id'],
            'occupation_master_id' => ['nullable', 'integer', 'exists:master_occupations,id'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'annual_income' => ['nullable', 'string', 'max:64'],
        ], [
            'full_name.required' => 'नाव आवश्यक आहे.',
            'mobile.required' => 'मोबाईल नंबर आवश्यक आहे.',
            'date_of_birth.required' => 'जन्मतारीख आवश्यक आहे.',
            'height_cm.required' => 'उंची निवडा.',
            'gender.required' => 'लिंग निवडा.',
            'mother_tongue_id.required' => 'मातृभाषा निवडा.',
            'marital_status_id.required' => 'वैवाहिक स्थिती निवडा.',
            'religion_id.required' => 'धर्म निवडा.',
            'caste_id.required' => 'जात निवडा.',
            'location.required' => 'ठिकाण भरा.',
            'education.required' => 'शिक्षण भरा.',
            'working_with_type_id.required' => 'कामाचा प्रकार निवडा.',
            'occupation_master_id.exists' => 'व्यवसाय यादीतून निवडा.',
        ])->validate();

        $workingWithType = WorkingWithType::query()->find((int) $validated['working_with_type_id']);
        $workingWithSlug = $this->stringOrNull($workingWithType?->slug);
        if (! $this->isOccupationExempt($workingWithSlug) && empty($validated['occupation_master_id'])) {
            throw ValidationException::withMessages([
                'occupation_master_id' => 'कृपया व्यवसाय निवडा.',
            ]);
        }

        $mobile = MobileNumber::normalize($validated['mobile']);
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => 'वैध १० अंकी मोबाईल नंबर भरा.',
            ]);
        }

        $snapshot = $this->sourceSnapshot($intake);
        $snapshot = $this->applyRegistrationInput($snapshot, $validated, $mobile);

        $intake = $this->reviewSnapshotService->saveReviewedSnapshot($intake, $snapshot, [
            'reviewed_by_user_id' => null,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_WEBSITE,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2C_PROFILE_USER_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        $this->markRegistrationComplete($item);

        return $intake->refresh();
    }

    private function markRegistrationComplete(BulkIntakeBatchItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
            $meta['registration'] = array_merge($existing, [
                'status' => BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE,
                'completed_at' => now()->toISOString(),
                'completed_via' => 'public_web_form',
            ]);
            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function applyRegistrationInput(array $snapshot, array $input, string $mobile): array
    {
        $snapshot['core'] = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $heightCm = (int) $input['height_cm'];

        $snapshot['core']['full_name'] = trim((string) $input['full_name']);
        $snapshot['core']['primary_contact_number'] = $mobile;
        $snapshot['core']['all_contact_numbers'] = [$mobile];
        $snapshot['core']['date_of_birth'] = $input['date_of_birth'];
        $snapshot['core']['height_cm'] = $heightCm;
        $snapshot['core']['height'] = HeightDisplay::formatFeetInches($heightCm);
        $snapshot['core']['gender'] = $this->normalizedGenderLabel($input['gender']);
        $snapshot['core']['gender_id'] = $this->genderIdFromValue($input['gender']);
        $snapshot['core']['mother_tongue_id'] = (int) $input['mother_tongue_id'];
        $snapshot['core']['marital_status_id'] = (int) $input['marital_status_id'];
        $snapshot['core']['religion_id'] = (int) $input['religion_id'];
        $snapshot['core']['caste_id'] = (int) $input['caste_id'];
        $snapshot['core']['city_text'] = trim((string) $input['location']);
        $snapshot['core']['address_line'] = trim((string) $input['location']);
        $snapshot['core']['highest_education'] = trim((string) $input['education']);
        $snapshot['core']['working_with_type_id'] = (int) $input['working_with_type_id'];

        $workingWithType = WorkingWithType::query()->find((int) $input['working_with_type_id']);
        $snapshot['core']['working_with'] = $this->stringOrNull($workingWithType?->slug) ?? '';
        $snapshot['core']['company_name'] = $this->stringOrNull($input['company_name'] ?? null);
        $snapshot['core']['annual_income'] = $this->stringOrNull($input['annual_income'] ?? null);

        $occupationMasterId = $this->intOrNull($input['occupation_master_id'] ?? null);
        if ($occupationMasterId !== null) {
            $occupation = OccupationMaster::query()->find($occupationMasterId);
            $snapshot['core']['occupation_master_id'] = $occupationMasterId;
            $title = $occupation ? $this->preferredLabel($occupation, 'name_mr', 'name') : null;
            if ($title !== '') {
                $snapshot['core']['occupation_title'] = $title;
                $snapshot['core']['occupation'] = $title;
            }
        } else {
            unset($snapshot['core']['occupation_master_id'], $snapshot['core']['occupation_title'], $snapshot['core']['occupation']);
        }

        $snapshot = $this->intakePipeline->normalizeBulkCandidateCorrectionSnapshot($snapshot);

        return $this->applyPrimaryContact($snapshot, $mobile);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function applyPrimaryContact(array $snapshot, string $mobile): array
    {
        $contacts = is_array($snapshot['contacts'] ?? null) ? array_values($snapshot['contacts']) : [];
        $targetIndex = null;
        foreach ($contacts as $index => $contact) {
            if (! is_array($contact)) {
                continue;
            }
            if (($contact['is_primary'] ?? false) === true || ($contact['contact_type'] ?? '') === 'self_primary') {
                $targetIndex = $index;
                break;
            }
        }

        $row = [
            'contact_type' => 'self_primary',
            'phone_number' => $mobile,
            'is_primary' => true,
        ];

        if ($targetIndex === null) {
            $contacts[] = $row;
        } else {
            $contacts[$targetIndex] = array_merge(is_array($contacts[$targetIndex]) ? $contacts[$targetIndex] : [], $row);
        }

        $snapshot['contacts'] = $contacts;

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSnapshot(BiodataIntake $intake): array
    {
        if (is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== []) {
            return $intake->approval_snapshot_json;
        }

        if (is_array($intake->parsed_json) && $intake->parsed_json !== []) {
            return $intake->parsed_json;
        }

        return [];
    }

    private function intakeForItem(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $item->loadMissing('biodataIntake');

        return $item->biodataIntake;
    }

    private function snapshotLocked(BiodataIntake $intake): bool
    {
        return (bool) $intake->approved_by_user || (bool) $intake->intake_locked;
    }

    public function accessDeniedMessage(string $reason): string
    {
        return match ($reason) {
            'consent_not_received' => 'Registration link is not active until WhatsApp consent is received.',
            'missing_intake' => 'Registration data is missing.',
            'snapshot_locked' => 'This registration can no longer be edited.',
            default => 'Registration link is not available.',
        };
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function heightCmFromCore(array $core): ?int
    {
        $heightCm = $core['height_cm'] ?? null;
        if (is_numeric($heightCm)) {
            $cm = (int) round((float) $heightCm);

            return $cm >= 120 && $cm <= 220 ? $cm : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function genderValue(array $core): ?string
    {
        if (isset($core['gender_id']) && is_numeric($core['gender_id'])) {
            $gender = MasterGender::query()->find((int) $core['gender_id']);
            if ($gender) {
                return (string) ($gender->key ?? $gender->label ?? '');
            }
        }

        return $this->stringOrNull($core['gender'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function occupationText(array $core): ?string
    {
        return $this->stringOrNull($core['occupation_title'] ?? $core['occupation'] ?? null);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function genderOptions(): array
    {
        return MasterGender::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (MasterGender $row): array => [
                'id' => (int) $row->id,
                'label' => $this->preferredLabel($row, 'label_mr', 'label', 'key'),
                'key' => (string) ($row->key ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function motherTongueOptions(): array
    {
        return MasterMotherTongue::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (MasterMotherTongue $row): array => [
                'id' => (int) $row->id,
                'label' => $this->preferredLabel($row, 'label_mr', 'label', 'key'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function maritalStatusOptions(): array
    {
        return MasterMaritalStatus::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (MasterMaritalStatus $row): array => [
                'id' => (int) $row->id,
                'label' => $this->preferredLabel($row, 'label_mr', 'label', 'key'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function religionOptions(): array
    {
        return Religion::query()
            ->where(function ($q): void {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('label')
            ->get()
            ->map(fn (Religion $row): array => [
                'id' => (int) $row->id,
                'label' => $this->preferredLabel($row, 'label_mr', 'label_en', 'label', 'key'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function casteOptions(): array
    {
        return Caste::query()
            ->where(function ($q): void {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('label')
            ->get()
            ->map(fn (Caste $row): array => [
                'id' => (int) $row->id,
                'label' => $this->preferredLabel($row, 'label_mr', 'label_en', 'label', 'key'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, slug: string, label: string}>
     */
    private function workingWithOptions(): array
    {
        return WorkingWithType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (WorkingWithType $row): array => [
                'id' => (int) $row->id,
                'slug' => (string) ($row->slug ?? ''),
                'label' => $this->preferredLabel($row, 'name_mr', 'name'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, working_with_type_id: int|null}>
     */
    private function occupationOptions(): array
    {
        return OccupationMaster::query()
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (OccupationMaster $row): array {
                $workingWithTypeId = $row->category?->legacy_working_with_type_id;

                return [
                    'id' => (int) $row->id,
                    'label' => $this->preferredLabel($row, 'name_mr', 'name'),
                    'working_with_type_id' => is_numeric($workingWithTypeId) ? (int) $workingWithTypeId : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function resolveMotherTongueId(BiodataIntake $intake, array $core): ?int
    {
        $existing = $this->intOrNull($core['mother_tongue_id'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        if (! $this->biodataLooksMarathi($intake, $core)) {
            return null;
        }

        $marathi = MasterMotherTongue::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('key', 'marathi')->orWhere('label', 'Marathi');
            })
            ->orderBy('sort_order')
            ->first();

        return $marathi ? (int) $marathi->id : null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function biodataLooksMarathi(BiodataIntake $intake, array $core): bool
    {
        $samples = array_filter([
            is_string($intake->raw_ocr_text ?? null) ? $intake->raw_ocr_text : null,
            is_string($core['full_name'] ?? null) ? $core['full_name'] : null,
            is_string($core['highest_education'] ?? null) ? $core['highest_education'] : null,
            is_string($core['city_text'] ?? null) ? $core['city_text'] : null,
            is_string($core['address_line'] ?? null) ? $core['address_line'] : null,
        ], fn (?string $value): bool => is_string($value) && trim($value) !== '');

        $text = implode(' ', $samples);
        if ($text === '') {
            return false;
        }

        $devanagari = preg_match_all('/\p{Devanagari}/u', $text, $devanagariMatches);
        $latin = preg_match_all('/\p{Latin}/u', $text, $latinMatches);
        $devanagariCount = $devanagari === false ? 0 : $devanagari;
        $latinCount = $latin === false ? 0 : $latin;

        return $devanagariCount >= 8 && $devanagariCount >= $latinCount;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function workingWithTypeIdFromCore(array $core): ?int
    {
        if (isset($core['working_with_type_id']) && is_numeric($core['working_with_type_id'])) {
            return (int) $core['working_with_type_id'];
        }

        $slug = $this->normalizeWorkingWithSlug($core['working_with'] ?? null);
        if ($slug === null) {
            return null;
        }

        $row = WorkingWithType::query()->where('slug', $slug)->first();

        return $row ? (int) $row->id : null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function occupationMasterIdFromCore(array $core): ?int
    {
        if (isset($core['occupation_master_id']) && is_numeric($core['occupation_master_id'])) {
            return (int) $core['occupation_master_id'];
        }

        $text = $this->occupationText($core);
        if ($text === null) {
            return null;
        }

        $row = OccupationMaster::query()
            ->where('name', $text)
            ->orWhere('name_mr', $text)
            ->orWhere('normalized_name', mb_strtolower($text, 'UTF-8'))
            ->first();

        return $row ? (int) $row->id : null;
    }

    private function normalizeWorkingWithSlug(mixed $value): ?string
    {
        $slug = $this->stringOrNull($value);
        if ($slug === null) {
            return null;
        }

        return match ($slug) {
            'government' => 'government_public_sector',
            'business', 'self_employed' => 'business_self_employed',
            default => $slug,
        };
    }

    private function isOccupationExempt(?string $workingWithSlug): bool
    {
        return in_array((string) $workingWithSlug, self::OCCUPATION_EXEMPT_SLUGS, true);
    }

    private function normalizedGenderLabel(mixed $value): ?string
    {
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }

        return match ($text) {
            'male', 'm', 'पुरुष' => 'male',
            'female', 'f', 'स्त्री', 'महिला' => 'female',
            default => $text,
        };
    }

    private function genderIdFromValue(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $normalized = $this->normalizedGenderLabel($value);
        if ($normalized === null) {
            return null;
        }

        $gender = MasterGender::query()->where('key', $normalized)->first();

        return $gender ? (int) $gender->id : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function registrationComplete(BulkIntakeBatchItem $item): bool
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $status = (string) (data_get($meta, 'registration.status') ?? '');

        return $status === BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function preferredLabel(object $row, string ...$attributes): string
    {
        foreach ($attributes as $attribute) {
            $value = $row->{$attribute} ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
