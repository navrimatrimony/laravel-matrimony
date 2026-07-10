<?php

namespace App\Services\Intake;

use App\Http\Controllers\ProfileWizardController;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\Location;
use App\Models\MasterChildLivingWith;
use App\Models\MasterGender;
use App\Models\MasterIncomeCurrency;
use App\Models\MasterMaritalStatus;
use App\Models\MasterMotherTongue;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\Caste;
use App\Services\EducationService;
use App\Services\Location\LocationNormalizationService;
use App\Services\OccupationService;
use App\Support\HeightDisplay;
use App\Support\MobileNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Bridges canonical onboarding/wizard form engines to bulk-intake public registration (snapshot-only, no profile row).
 */
class BulkIntakeRegistrationFormBridgeService
{
    public function __construct(
        private readonly IntakePipelineService $intakePipeline,
    ) {}

    /**
     * @return array{
     *     profile: MatrimonyProfile,
     *     genders: \Illuminate\Support\Collection<int, MasterGender>,
     *     mother_tongues: list<array{id: int, label: string}>,
     *     marital_statuses: \Illuminate\Support\Collection<int, MasterMaritalStatus>,
     *     profile_marriages: \Illuminate\Support\Collection<int, object>,
     *     profile_children: \Illuminate\Support\Collection<int, object>,
     *     child_living_with_options: \Illuminate\Support\Collection<int, MasterChildLivingWith>,
     *     currencies: \Illuminate\Support\Collection<int, MasterIncomeCurrency>,
     *     residence_hints: array{location_id: string, country_id: string, state_id: string, district_id: string, taluka_id: string},
     *     residence_display: string,
     *     mobile: string|null,
     *     candidate_name: string|null,
     * }
     */
    public function viewContext(BulkIntakeBatchItem $item, array $snapshot, ?string $candidateName, ?string $mobile): array
    {
        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $preferMarathiLabels = $this->biodataLooksMarathi($intake, $core);
        $profile = $this->profileFromSnapshot($snapshot, $item);
        if ($preferMarathiLabels && $profile->occupationMaster && filled($profile->occupationMaster->name_mr)) {
            $profile->occupationMaster->name = (string) $profile->occupationMaster->name_mr;
        }

        return [
            'profile' => $profile,
            'prefer_marathi_labels' => $preferMarathiLabels,
            'genders' => MasterGender::query()
                ->where('is_active', true)
                ->whereIn('key', ['male', 'female'])
                ->orderByRaw("CASE WHEN `key` = 'male' THEN 1 ELSE 2 END")
                ->get(),
            'mother_tongues' => $this->motherTongueOptions($preferMarathiLabels),
            'marital_statuses' => $this->maritalStatuses($preferMarathiLabels),
            'profile_marriages' => $this->marriagesCollection($snapshot),
            'profile_children' => $this->childrenCollection($snapshot),
            'child_living_with_options' => $this->childLivingWithOptions(),
            'currencies' => MasterIncomeCurrency::query()->where('is_active', true)->get(),
            'residence_hints' => $profile->residenceLocationHierarchyHints(),
            'residence_display' => $this->residenceDisplayLine($core, $profile),
            'mobile' => $mobile,
            'candidate_name' => $candidateName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshotFromRequest(Request $request, BulkIntakeBatchItem $item, BiodataIntake $intake): array
    {
        $mobile = MobileNumber::normalize((string) $request->input('mobile', ''));
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => 'वैध १० अंकी मोबाईल नंबर भरा.',
            ]);
        }

        $existing = $this->sourceSnapshot($intake);
        $profile = $this->profileFromSnapshot($existing, $item);

        $this->prepareRequestDefaults($request, $existing, $profile);

        $educationService = app(EducationService::class);
        if (Schema::hasColumn('matrimony_profiles', 'highest_education')) {
            $educationService->mergeMultiselectEducationIntoRequest($request);
        }

        if (Schema::hasColumn('matrimony_profiles', 'occupation_master_id')) {
            app(OccupationService::class)->mergeOccupationIntoRequest($request);
        }

        $wizard = app(ProfileWizardController::class);
        $request->attributes->set(ProfileWizardController::SKIP_BASIC_INFO_RESIDENCE_VALIDATION, true);

        $basic = $wizard->buildSnapshotForSection($request, 'basic-info', $profile);
        $physical = $wizard->buildOnboardingPhysicalAddressSnapshot($request, $profile);
        $educationCareer = $wizard->buildSnapshotForSection($request, 'education-career', $profile);

        if ($basic === null || $educationCareer === null) {
            throw ValidationException::withMessages([
                'registration' => 'नोंदणी माहिती जतन करता आली नाही.',
            ]);
        }

        $mergedCore = array_merge(
            is_array($existing['core'] ?? null) ? $existing['core'] : [],
            is_array($basic['core'] ?? null) ? $basic['core'] : [],
            is_array($physical['core'] ?? null) ? $physical['core'] : [],
            is_array($educationCareer['core'] ?? null) ? $educationCareer['core'] : [],
        );

        if (isset($mergedCore['height_cm']) && is_numeric($mergedCore['height_cm'])) {
            $heightCm = (int) round((float) $mergedCore['height_cm']);
            $mergedCore['height_cm'] = $heightCm;
            $mergedCore['height'] = HeightDisplay::formatFeetInches($heightCm);
        }

        $gender = MasterGender::query()->find((int) ($mergedCore['gender_id'] ?? 0));
        if ($gender) {
            $mergedCore['gender'] = (string) ($gender->key ?? $gender->label ?? '');
        }

        $workingWithSlug = null;
        if (! empty($mergedCore['working_with_type_id'])) {
            $workingWithSlug = \App\Models\WorkingWithType::query()
                ->whereKey((int) $mergedCore['working_with_type_id'])
                ->value('slug');
        }
        if (is_string($workingWithSlug) && $workingWithSlug !== '') {
            $mergedCore['working_with'] = $workingWithSlug;
        }

        $snapshot = $existing;
        $snapshot['core'] = $mergedCore;
        $snapshot['marriages'] = is_array($basic['marriages'] ?? null) ? $basic['marriages'] : [];
        $snapshot['children'] = is_array($basic['children'] ?? null) ? $basic['children'] : [];

        if (! empty($mergedCore['location_id'])) {
            $display = Location::query()->find((int) $mergedCore['location_id'])?->localizedName();
            if (is_string($display) && trim($display) !== '') {
                $snapshot['core']['city_text'] = trim($display);
                $snapshot['core']['address_line'] = trim($display);
            }
        } elseif (! empty($mergedCore['location_input'])) {
            $text = trim((string) $mergedCore['location_input']);
            $snapshot['core']['city_text'] = $text;
            $snapshot['core']['address_line'] = $text;
        }

        $snapshot['core']['primary_contact_number'] = $mobile;
        $snapshot['core']['all_contact_numbers'] = [$mobile];
        $snapshot = $this->applyPrimaryContact($snapshot, $mobile);
        $snapshot = $this->intakePipeline->normalizeBulkCandidateCorrectionSnapshot($snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function profileFromSnapshot(array $snapshot, BulkIntakeBatchItem $item): MatrimonyProfile
    {
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $intake = $item->biodataIntake;

        $profile = new MatrimonyProfile();
        $profile->forceFill([
            'full_name' => $core['full_name'] ?? null,
            'date_of_birth' => $core['date_of_birth'] ?? null,
            'gender_id' => $this->intOrNull($core['gender_id'] ?? null),
            'height_cm' => $this->intOrNull($core['height_cm'] ?? null),
            'religion_id' => $this->intOrNull($core['religion_id'] ?? null),
            'caste_id' => $this->intOrNull($core['caste_id'] ?? null),
            'sub_caste_id' => $this->intOrNull($core['sub_caste_id'] ?? null),
            'mother_tongue_id' => $this->intOrNull($core['mother_tongue_id'] ?? null),
            'marital_status_id' => $this->intOrNull($core['marital_status_id'] ?? null),
            'has_children' => $core['has_children'] ?? null,
            'location_id' => $this->intOrNull($core['location_id'] ?? null),
            'address_line' => $this->stringOrNull($core['address_line'] ?? $core['city_text'] ?? null),
            'highest_education' => $this->stringOrNull($core['highest_education'] ?? null),
            'highest_education_other' => $this->stringOrNull($core['highest_education_other'] ?? null),
            'working_with_type_id' => $this->intOrNull($core['working_with_type_id'] ?? null),
            'profession_id' => $this->intOrNull($core['profession_id'] ?? null),
            'occupation_master_id' => $this->intOrNull($core['occupation_master_id'] ?? null),
            'occupation_custom_id' => $this->intOrNull($core['occupation_custom_id'] ?? null),
            'company_name' => $this->stringOrNull($core['company_name'] ?? null),
            'annual_income' => $core['annual_income'] ?? null,
            'income_period' => $this->stringOrNull($core['income_period'] ?? null),
            'income_value_type' => $this->stringOrNull($core['income_value_type'] ?? null),
            'income_amount' => $core['income_amount'] ?? null,
            'income_min_amount' => $core['income_min_amount'] ?? null,
            'income_max_amount' => $core['income_max_amount'] ?? null,
            'income_currency_id' => $this->intOrNull($core['income_currency_id'] ?? null),
            'income_private' => (bool) ($core['income_private'] ?? false),
            'income_range_id' => $this->intOrNull($core['income_range_id'] ?? null),
        ]);

        if ($profile->religion_id) {
            $profile->setRelation('religion', Religion::query()->find($profile->religion_id));
        }
        if ($profile->caste_id) {
            $profile->setRelation('caste', Caste::query()->find($profile->caste_id));
        }
        if ($profile->occupation_master_id) {
            $profile->loadMissing(['occupationMaster.category.workingWithType']);
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function prepareDisplaySnapshot(array $snapshot, BiodataIntake $intake): array
    {
        if ($snapshot === []) {
            return ['core' => []];
        }

        $snapshot = $this->intakePipeline->normalizeApprovedSnapshot($snapshot, null);
        $snapshot = $this->backfillRegistrationCoreFromSnapshot($snapshot);

        return $snapshot;
    }

    public function biodataLooksMarathi(BiodataIntake $intake, array $core): bool
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

    public function resolveMotherTongueId(BiodataIntake $intake, array $core): ?int
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
     * @param  array<string, mixed>  $snapshot
     * @return Collection<int, object>
     */
    private function marriagesCollection(array $snapshot): Collection
    {
        $rows = is_array($snapshot['marriages'] ?? null) ? $snapshot['marriages'] : [];

        return collect($rows)->values()->map(function (mixed $row): object {
            $data = is_array($row) ? $row : [];

            return (object) [
                'id' => $data['id'] ?? null,
                'marriage_year' => $data['marriage_year'] ?? null,
                'divorce_year' => $data['divorce_year'] ?? null,
                'separation_year' => $data['separation_year'] ?? null,
                'spouse_death_year' => $data['spouse_death_year'] ?? null,
                'divorce_status' => $data['divorce_status'] ?? null,
                'remarriage_reason' => $data['remarriage_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return Collection<int, object>
     */
    private function childrenCollection(array $snapshot): Collection
    {
        $rows = is_array($snapshot['children'] ?? null) ? $snapshot['children'] : [];

        return collect($rows)->values()->map(function (mixed $row): object {
            $data = is_array($row) ? $row : [];

            return (object) [
                'id' => $data['id'] ?? null,
                'gender' => $data['gender'] ?? '',
                'age' => $data['age'] ?? '',
                'child_living_with_id' => $data['child_living_with_id'] ?? '',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function residenceDisplayLine(array $core, MatrimonyProfile $profile): string
    {
        if ($profile->location_id) {
            return MatrimonyProfile::residenceLocationDisplayLineFor($profile);
        }

        return trim((string) ($core['city_text'] ?? $core['address_line'] ?? $core['location_input'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function prepareRequestDefaults(Request $request, array $snapshot, MatrimonyProfile $profile): void
    {
        $marriage = $this->marriagesCollection($snapshot)->first();
        if (! $request->has('marriages')) {
            $request->merge([
                'marriages' => [[
                    'id' => $marriage?->id,
                    'marriage_year' => $marriage->marriage_year ?? '',
                    'divorce_year' => $marriage->divorce_year ?? '',
                    'separation_year' => $marriage->separation_year ?? '',
                    'spouse_death_year' => $marriage->spouse_death_year ?? '',
                    'divorce_status' => $marriage->divorce_status ?? '',
                ]],
            ]);
        }

        if ($request->input('has_children') === null && $profile->has_children !== null) {
            $request->merge([
                'has_children' => $profile->has_children ? '1' : '0',
            ]);
        }

        if (! $request->has('children')) {
            $request->merge([
                'children' => $this->childrenCollection($snapshot)->map(fn (object $child): array => [
                    'id' => $child->id ?? null,
                    'gender' => $child->gender ?? '',
                    'age' => $child->age ?? '',
                    'child_living_with_id' => $child->child_living_with_id ?? '',
                ])->values()->all(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function backfillRegistrationCoreFromSnapshot(array $snapshot): array
    {
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }

        $core = &$snapshot['core'];

        if (empty($core['location_id'])) {
            $core['location_id'] = $this->resolveResidenceLocationId($snapshot);
        }

        $career = is_array($snapshot['career_history'] ?? null) ? ($snapshot['career_history'][0] ?? null) : null;
        if (is_array($career)) {
            foreach ([
                'occupation_master_id' => 'occupation_master_id',
                'occupation_custom_id' => 'occupation_custom_id',
                'company_name' => 'company_name',
                'working_with_type_id' => 'working_with_type_id',
                'profession_id' => 'profession_id',
            ] as $coreKey => $careerKey) {
                if (empty($core[$coreKey]) && ! empty($career[$careerKey])) {
                    $core[$coreKey] = $career[$careerKey];
                }
            }
            if (empty($core['occupation_title']) && ! empty($career['occupation_title'])) {
                $core['occupation_title'] = $career['occupation_title'];
            }
        }

        if (empty($core['occupation_master_id'])) {
            $occupationText = trim((string) ($core['occupation_title'] ?? $core['occupation'] ?? $core['job'] ?? ''));
            if ($occupationText !== '') {
                $occupation = app(OccupationService::class)->findOccupationMasterForIntake($occupationText);
                if ($occupation !== null) {
                    $core['occupation_master_id'] = (int) $occupation->id;
                }
            }
        }

        if ($this->stringOrNull($core['highest_education'] ?? null) === null) {
            $educationHistory = is_array($snapshot['education_history'] ?? null) ? $snapshot['education_history'] : [];
            $parts = [];
            foreach ($educationHistory as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $degree = trim((string) ($row['degree'] ?? $row['qualification'] ?? ''));
                if ($degree !== '') {
                    $parts[] = $degree;
                }
            }
            if ($parts !== []) {
                $core['highest_education'] = implode(', ', array_values(array_unique($parts)));
            }
        }

        if ($this->stringOrNull($core['company_name'] ?? null) === null) {
            foreach (['company', 'employer', 'organization'] as $companyKey) {
                $company = trim((string) ($core[$companyKey] ?? ''));
                if ($company !== '') {
                    $core['company_name'] = $company;
                    break;
                }
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveResidenceLocationId(array $snapshot): ?int
    {
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $existing = $this->intOrNull($core['location_id'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        $addresses = is_array($snapshot['addresses'] ?? null) ? $snapshot['addresses'] : [];
        foreach ($addresses as $address) {
            if (! is_array($address)) {
                continue;
            }
            $locationId = $this->intOrNull($address['location_id'] ?? $address['city_id'] ?? null);
            if ($locationId !== null) {
                return $locationId;
            }
        }

        foreach ([
            $core['city_text'] ?? null,
            $core['city'] ?? null,
            $core['address_line'] ?? null,
            $core['location_input'] ?? null,
        ] as $candidate) {
            $text = is_string($candidate) ? trim($candidate) : '';
            if ($text === '' || str_contains($text, ',')) {
                continue;
            }
            $resolved = app(LocationNormalizationService::class)->normalizeFromText($text);
            $locationId = $resolved['location_id'] ?? $resolved['city_id'] ?? null;
            if (($resolved['confidence'] ?? 0.0) >= 0.80 && ($resolved['matched'] ?? false) && $locationId !== null) {
                return (int) $locationId;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, MasterMaritalStatus>
     */
    private function maritalStatuses(bool $preferMarathiLabels): Collection
    {
        $keys = ['never_married', 'divorced', 'annulled', 'separated', 'widowed'];
        $rows = MasterMaritalStatus::query()
            ->where('is_active', true)
            ->whereIn('key', $keys)
            ->get()
            ->sortBy(fn (MasterMaritalStatus $status): int => array_search($status->key, $keys, true) !== false
                ? (int) array_search($status->key, $keys, true)
                : 999)
            ->values();

        if ($rows->isEmpty()) {
            $rows = MasterMaritalStatus::query()->where('is_active', true)->orderBy('id')->get();
        }

        if ($preferMarathiLabels) {
            $rows->each(function (MasterMaritalStatus $status): void {
                $translated = __('wizard.'.$status->key);
                if ($translated !== 'wizard.'.$status->key) {
                    $status->label = $translated;
                }
            });
        }

        return $rows;
    }

    /**
     * @return Collection<int, MasterChildLivingWith>
     */
    private function childLivingWithOptions(): Collection
    {
        $keys = ['with_parent', 'with_other_parent', 'joint', 'other'];
        $rows = MasterChildLivingWith::query()
            ->where('is_active', true)
            ->whereIn('key', $keys)
            ->get()
            ->sortBy(fn (MasterChildLivingWith $option): int => array_search($option->key, $keys, true) !== false
                ? (int) array_search($option->key, $keys, true)
                : 999)
            ->values();

        if ($rows->isEmpty()) {
            return MasterChildLivingWith::query()->where('is_active', true)->orderBy('id')->get();
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function motherTongueOptions(bool $preferMarathiLabels): array
    {
        return MasterMotherTongue::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (MasterMotherTongue $row): array => [
                'id' => (int) $row->id,
                'label' => $preferMarathiLabels
                    ? $this->preferredLabel($row, 'label_mr', 'label', 'key')
                    : $this->preferredLabel($row, 'label_en', 'label', 'key'),
            ])
            ->values()
            ->all();
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

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
