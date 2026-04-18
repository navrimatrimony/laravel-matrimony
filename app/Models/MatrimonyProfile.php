<?php

namespace App\Models;

use App\Casts\MojibakeSafeUtf8String;
use App\Services\ConflictDetectionService;
use App\Services\ProfileFieldLockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| MatrimonyProfile Model
|--------------------------------------------------------------------------
|
| 👉 हा model MATRIMONY BIODATA साठी आहे
| 👉 User model पासून वेगळा ठेवलेला आहे (SSOT v3.1 rule)
| 👉 Search, Interest, View, Edit — सगळं याच model वर होईल
|
| लक्षात ठेव:
| User = login / auth only
| MatrimonyProfile = full biodata
|
*/

class MatrimonyProfile extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    |
    | Laravel default table नाव अंदाजाने काढतो.
    | पण clarity साठी आपण explicitly सांगतो.
    |
    */
    protected $table = 'matrimony_profiles';

    /** Sentinel for `card_onboarding_resume_step` after last onboarding card (step 4 → photo upload handoff). */
    public const CARD_ONBOARDING_PHOTO_RESUME_STEP = 8;

    /** Allowed lifecycle_state values (validated via mutator). */
    public const LIFECYCLE_STATES = [
        'draft',
        'intake_uploaded',
        'parsed',
        'awaiting_user_approval',
        'approved_pending_mutation',
        'conflict_pending',
        'active',
        'suspended',
        'archived',
        'archived_due_to_marriage',
    ];

    public const LIFECYCLE_TRANSITIONS = [
        'draft' => [],
        'intake_uploaded' => ['parsed'],
        'parsed' => ['awaiting_user_approval'],
        'awaiting_user_approval' => ['approved_pending_mutation'],
        'approved_pending_mutation' => ['active', 'conflict_pending'],
        'conflict_pending' => ['active'],
        'active' => ['suspended', 'archived', 'archived_due_to_marriage', 'conflict_pending'],
        'suspended' => ['active', 'archived'],
        'archived' => [],
        'archived_due_to_marriage' => [],
    ];

    /** Core field keys subject to lock + conflict governance (must match ConflictDetectionService). */
    private const GOVERNED_CORE_KEYS = [
        'full_name',
        'gender_id',
        'date_of_birth',
        'marital_status_id',
        'highest_education',
        'location',
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'height_cm',
        'profile_photo',
        'complexion_id',
        'physical_build_id',
        'blood_group_id',
        'family_type_id',
        'income_currency_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | Mass Assignable Fields
    |--------------------------------------------------------------------------
    |
    | create() / update() वापरताना
    | कोणते fields allow आहेत ते इथे सांगतो
    |
    | ⚠️ भविष्यात error आला तर:
    | "Add field to $fillable" हे लक्षात ठेव
    |
    */
    protected $fillable = [
        'user_id',
        'full_name',
        'gender_id',
        'date_of_birth',
        'birth_time',
        'marital_status_id',
        'has_children',
        'has_siblings',
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'highest_education',
        'highest_education_id',
        'highest_education_text',
        'education_degree_id',
        'education_text',
        'country_id',
        'state_id',
        'district_id',
        'taluka_id',
        'city_id',
        'address_line',
        'birth_city_id',
        'birth_taluka_id',
        'birth_district_id',
        'birth_state_id',
        'birth_place_text',
        'native_city_id',
        'native_taluka_id',
        'native_district_id',
        'native_state_id',
        'height_cm',
        'weight_kg',
        'weight_range',
        'profile_photo',
        'complexion_id',
        'physical_build_id',
        'blood_group_id',
        'spectacles_lens',
        'physical_condition',
        'family_type_id',
        'income_currency_id',
        'is_suspended',
        'lifecycle_state',
        'photo_approved',
        'photo_rejected_at',
        'photo_rejection_reason',
        'photo_moderation_snapshot',
        'is_showcase',
        'visibility_override',
        'visibility_override_reason',
        'edited_by',
        'edited_at',
        'edit_reason',
        'edited_source',
        'admin_edited_fields',
        'pending_intake_suggestions_json',
        'profile_visibility_mode',
        'contact_unlock_mode',
        'safety_defaults_applied',
        'serious_intent_id',

        // Day 31 Part 2: Education/Career + Family (DB columns exist; was missing from fillable)
        'specialization',
        'occupation_title',
        'company_name',
        'work_location_text',
        'annual_income',
        'working_with_type_id',
        'occupation_master_id',
        'occupation_custom_id',
        'profession_id',
        'income_range_id',
        'college_id',
        'income_private',
        'income_period',
        'income_value_type',
        'income_amount',
        'income_min_amount',
        'income_max_amount',
        'income_normalized_annual_amount',
        'family_income',
        'family_income_period',
        'family_income_value_type',
        'family_income_amount',
        'family_income_min_amount',
        'family_income_max_amount',
        'family_income_currency_id',
        'family_income_private',
        'family_income_normalized_annual_amount',
        'father_name',
        'father_occupation',
        'father_occupation_master_id',
        'father_occupation_custom_id',
        'father_extra_info',
        'father_contact_1',
        'father_contact_2',
        'father_contact_3',
        'mother_name',
        'mother_occupation',
        'mother_occupation_master_id',
        'mother_occupation_custom_id',
        'mother_extra_info',
        'mother_contact_1',
        'mother_contact_2',
        'mother_contact_3',
        // brothers_count, sisters_count: deprecated; use Siblings engine (profile_siblings).
        'work_city_id',
        'work_state_id',

        // Other Relatives engine (इतर नातेवाईक — आडनाव/गाव)
        'other_relatives_text',

        'mother_tongue_id',
        'diet_id',
        'smoking_status_id',
        'drinking_status_id',

        'card_onboarding_resume_step',
    ];

    protected $casts = [
        'is_suspended' => 'boolean',
        'photo_approved' => 'boolean',
        'photo_rejected_at' => 'datetime',
        'is_showcase' => 'boolean',
        'visibility_override' => 'boolean',
        'edited_at' => 'datetime',
        'admin_edited_fields' => 'array',
        'pending_intake_suggestions_json' => 'array',
        'safety_defaults_applied' => 'boolean',
        'income_private' => 'boolean',
        'family_income_private' => 'boolean',
        'card_onboarding_resume_step' => 'integer',
        'highest_education_id' => 'integer',
        'education_degree_id' => 'integer',
        // UTF-8 bytes misread as Latin-1 (mojibake) — repair on read/write for MR/EN narrative fields.
        'full_name' => MojibakeSafeUtf8String::class,
        'highest_education' => MojibakeSafeUtf8String::class,
        'highest_education_text' => MojibakeSafeUtf8String::class,
        'education_text' => MojibakeSafeUtf8String::class,
        'address_line' => MojibakeSafeUtf8String::class,
        'birth_place_text' => MojibakeSafeUtf8String::class,
        'photo_rejection_reason' => MojibakeSafeUtf8String::class,
        'photo_moderation_snapshot' => 'array',
        'visibility_override_reason' => MojibakeSafeUtf8String::class,
        'edit_reason' => MojibakeSafeUtf8String::class,
        'specialization' => MojibakeSafeUtf8String::class,
        'occupation_title' => MojibakeSafeUtf8String::class,
        'company_name' => MojibakeSafeUtf8String::class,
        'work_location_text' => MojibakeSafeUtf8String::class,
        'annual_income' => MojibakeSafeUtf8String::class,
        'family_income' => MojibakeSafeUtf8String::class,
        'father_name' => MojibakeSafeUtf8String::class,
        'father_occupation' => MojibakeSafeUtf8String::class,
        'father_extra_info' => MojibakeSafeUtf8String::class,
        'father_contact_1' => MojibakeSafeUtf8String::class,
        'father_contact_2' => MojibakeSafeUtf8String::class,
        'father_contact_3' => MojibakeSafeUtf8String::class,
        'mother_name' => MojibakeSafeUtf8String::class,
        'mother_occupation' => MojibakeSafeUtf8String::class,
        'mother_extra_info' => MojibakeSafeUtf8String::class,
        'mother_contact_1' => MojibakeSafeUtf8String::class,
        'mother_contact_2' => MojibakeSafeUtf8String::class,
        'mother_contact_3' => MojibakeSafeUtf8String::class,
        'other_relatives_text' => MojibakeSafeUtf8String::class,
        'physical_condition' => MojibakeSafeUtf8String::class,
        'weight_range' => MojibakeSafeUtf8String::class,
    ];

    /**
     * Primary contact number from profile_contacts (relation-based). No direct column.
     * Falls back to the account mobile (registration / OTP) when no primary contact row exists.
     */
    public function getPrimaryContactNumberAttribute(): ?string
    {
        $phone = DB::table('profile_contacts')
            ->where('profile_id', $this->id)
            ->where('is_primary', true)
            ->value('phone_number');

        if ($phone !== null && trim((string) $phone) !== '') {
            return trim((string) $phone);
        }

        $mobile = $this->user?->mobile ?? null;
        if ($mobile !== null && trim((string) $mobile) !== '') {
            return trim((string) $mobile);
        }

        return null;
    }

    /**
     * Legacy accessor: contact_number now sourced from primary profile_contacts.
     */
    public function getContactNumberAttribute(): ?string
    {
        return $this->primary_contact_number;
    }

    /**
     * Profile photo URL for UI (chat/inbox/etc).
     * Uses `profile_photo` column (primary photo filename) when present.
     */
    public function getProfilePhotoUrlAttribute(): string
    {
        $file = trim((string) ($this->profile_photo ?? ''));
        if ($file !== '') {
            return app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($file);
        }

        $genderKey = $this->gender?->key ?? $this->gender_id ?? $this->user?->gender ?? null;
        if ($genderKey === 'male') {
            return asset('images/placeholders/male-profile.svg');
        }
        if ($genderKey === 'female') {
            return asset('images/placeholders/female-profile.svg');
        }

        return asset('images/placeholders/default-profile.svg');
    }

    public function getIsShowcaseAttribute(): bool
    {
        $raw = $this->attributes['is_showcase'] ?? null;

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN) || (string) $raw === '1';
    }

    public function setIsShowcaseAttribute(mixed $value): void
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN) || (string) $value === '1';
        $this->attributes['is_showcase'] = $bool ? 1 : 0;
    }

    public function isShowcaseProfile(): bool
    {
        return (bool) $this->is_showcase;
    }

    public function scopeWhereShowcase(Builder $query): Builder
    {
        return $query->where('is_showcase', true);
    }

    public function scopeWhereNonShowcase(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('is_showcase', false)->orWhereNull('is_showcase');
        });
    }

    /**
     * Profile photo gallery (sorted + primary first in UI ordering).
     */
    public function photos()
    {
        return $this->hasMany(ProfilePhoto::class, 'profile_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Single primary photo record.
     */
    public function primaryPhotoRecord()
    {
        return $this->hasOne(ProfilePhoto::class, 'profile_id')
            ->where('is_primary', true);
    }

    public function setLifecycleStateAttribute($value): void
    {
        if (! in_array($value, self::LIFECYCLE_STATES, true)) {
            throw new \InvalidArgumentException("Invalid lifecycle_state: {$value}");
        }

        $this->attributes['lifecycle_state'] = $value;
    }

    public function transitionLifecycle(string $newState): void
    {
        if (! in_array($newState, self::LIFECYCLE_STATES, true)) {
            throw new \InvalidArgumentException("Invalid lifecycle_state: {$newState}");
        }

        $current = $this->lifecycle_state;

        $allowed = self::LIFECYCLE_TRANSITIONS[$current] ?? [];

        if (! in_array($newState, $allowed, true)) {
            throw new \LogicException("Illegal lifecycle transition: {$current} → {$newState}");
        }

        $this->lifecycle_state = $newState;
    }

    /**
     * Partner preference criteria (one row per profile).
     */
    public function preferenceCriteria()
    {
        return $this->hasOne(ProfilePreferenceCriteria::class, 'profile_id');
    }

    /**
     * Preferred religions for this profile (pivot).
     */
    public function preferredReligions()
    {
        return $this->belongsToMany(Religion::class, 'profile_preferred_religions', 'profile_id', 'religion_id');
    }

    /**
     * Preferred castes for this profile (pivot).
     */
    public function preferredCastes()
    {
        return $this->belongsToMany(Caste::class, 'profile_preferred_castes', 'profile_id', 'caste_id');
    }

    /**
     * Preferred districts for this profile (pivot).
     */
    public function preferredDistricts()
    {
        return $this->belongsToMany(District::class, 'profile_preferred_districts', 'profile_id', 'district_id');
    }

    /**
     * Highest education via Shaadi-style degree master ({@see EducationDegree}).
     */
    public function educationDegree(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EducationDegree::class, 'education_degree_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationship: MatrimonyProfile → User
    |--------------------------------------------------------------------------
    |
    | एक MatrimonyProfile एका User शी belong करतो
    |
    | वापर:
    | $matrimonyProfile->user
    |
    */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Exclude profiles tied to admin/staff accounts ({@see User::isAnyAdmin()}).
     */
    public function scopeWhereMemberAccountsOnly(Builder $query): Builder
    {
        return $query->whereHas('user', function ($q) {
            $q->where(function ($q2) {
                $q2->whereNull('is_admin')->orWhere('is_admin', false);
            })->whereNull('admin_role');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Location Hierarchy Relationships (Phase-4 Day-8)
    |--------------------------------------------------------------------------
    */

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function taluka()
    {
        return $this->belongsTo(Taluka::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Human-readable current residence line (city, taluka, district, state) for location typeahead `value=`.
     * Matches the label shape used when a row is picked in JS (village/city first).
     */
    public static function residenceLocationDisplayLineFromIds(
        mixed $cityId,
        mixed $talukaId,
        mixed $districtId,
        mixed $stateId,
    ): string {
        if (! $cityId && ! $talukaId && ! $districtId && ! $stateId) {
            return '';
        }
        $parts = [];
        if ($cityId) {
            $parts[] = (string) (City::query()->where('id', (int) $cityId)->value('name') ?? '');
        }
        if ($talukaId) {
            $parts[] = (string) (Taluka::query()->where('id', (int) $talukaId)->value('name') ?? '');
        }
        if ($districtId) {
            $parts[] = (string) (District::query()->where('id', (int) $districtId)->value('name') ?? '');
        }
        if ($stateId) {
            $parts[] = (string) (State::query()->where('id', (int) $stateId)->value('name') ?? '');
        }

        return implode(', ', array_values(array_filter($parts, static fn ($p) => $p !== '')));
    }

    /**
     * Shared by wizard basic_info and intake preview (where $profile may be a stdClass snapshot row).
     *
     * @param  self|object  $profileOrRow
     */
    public static function residenceLocationDisplayLineFor(mixed $profileOrRow): string
    {
        if ($profileOrRow instanceof self) {
            return $profileOrRow->residenceLocationDisplayLine();
        }
        if (! is_object($profileOrRow)) {
            return '';
        }

        return self::residenceLocationDisplayLineFromIds(
            $profileOrRow->city_id ?? null,
            $profileOrRow->taluka_id ?? null,
            $profileOrRow->district_id ?? null,
            $profileOrRow->state_id ?? null,
        );
    }

    /**
     * Human-readable current residence line (city, taluka, district, state) for location typeahead `value=`.
     * Matches the label shape used when a row is picked in JS (village/city first).
     */
    public function residenceLocationDisplayLine(): string
    {
        return self::residenceLocationDisplayLineFromIds(
            $this->city_id,
            $this->taluka_id,
            $this->district_id,
            $this->state_id,
        );
    }

    /**
     * Short residence line for compact UI (e.g. chat dock): district + state only.
     */
    public static function residenceDistrictAndStateLineFromIds(?int $districtId, ?int $stateId): string
    {
        $parts = [];
        if ($districtId) {
            $n = District::query()->where('id', $districtId)->value('name');
            if ($n) {
                $parts[] = trim((string) $n);
            }
        }
        if ($stateId) {
            $n = State::query()->where('id', $stateId)->value('name');
            if ($n) {
                $parts[] = trim((string) $n);
            }
        }

        return implode(', ', array_values(array_filter($parts, static fn ($p) => $p !== '')));
    }

    public function residenceDistrictStateLine(): string
    {
        return self::residenceDistrictAndStateLineFromIds(
            $this->district_id ? (int) $this->district_id : null,
            $this->state_id ? (int) $this->state_id : null,
        );
    }

    public function birthCity()
    {
        return $this->belongsTo(City::class, 'birth_city_id');
    }

    public function birthTaluka()
    {
        return $this->belongsTo(Taluka::class, 'birth_taluka_id');
    }

    public function birthDistrict()
    {
        return $this->belongsTo(District::class, 'birth_district_id');
    }

    public function birthState()
    {
        return $this->belongsTo(State::class, 'birth_state_id');
    }

    public function nativeCity()
    {
        return $this->belongsTo(City::class, 'native_city_id');
    }

    public function nativeTaluka()
    {
        return $this->belongsTo(Taluka::class, 'native_taluka_id');
    }

    public function nativeDistrict()
    {
        return $this->belongsTo(District::class, 'native_district_id');
    }

    public function nativeState()
    {
        return $this->belongsTo(State::class, 'native_state_id');
    }

    public function seriousIntent()
    {
        return $this->belongsTo(SeriousIntent::class);
    }

    /** Phase-5 SSOT: Master lookup relationships (*_id). */
    public function gender()
    {
        return $this->belongsTo(MasterGender::class, 'gender_id');
    }

    public function religion()
    {
        return $this->belongsTo(\App\Models\Religion::class);
    }

    public function caste()
    {
        return $this->belongsTo(\App\Models\Caste::class);
    }

    public function subCaste()
    {
        return $this->belongsTo(\App\Models\SubCaste::class);
    }

    public function maritalStatus()
    {
        return $this->belongsTo(MasterMaritalStatus::class, 'marital_status_id');
    }

    public function complexion()
    {
        return $this->belongsTo(MasterComplexion::class, 'complexion_id');
    }

    public function physicalBuild()
    {
        return $this->belongsTo(MasterPhysicalBuild::class, 'physical_build_id');
    }

    public function bloodGroup()
    {
        return $this->belongsTo(MasterBloodGroup::class, 'blood_group_id');
    }

    public function motherTongue()
    {
        return $this->belongsTo(MasterMotherTongue::class, 'mother_tongue_id');
    }

    public function diet()
    {
        return $this->belongsTo(MasterDiet::class, 'diet_id');
    }

    public function smokingStatus()
    {
        return $this->belongsTo(MasterSmokingStatus::class, 'smoking_status_id');
    }

    public function drinkingStatus()
    {
        return $this->belongsTo(MasterDrinkingStatus::class, 'drinking_status_id');
    }

    public function familyType()
    {
        return $this->belongsTo(MasterFamilyType::class, 'family_type_id');
    }

    public function incomeCurrency()
    {
        return $this->belongsTo(MasterIncomeCurrency::class, 'income_currency_id');
    }

    public function familyIncomeCurrency()
    {
        return $this->belongsTo(MasterIncomeCurrency::class, 'family_income_currency_id');
    }

    public function workingWithType()
    {
        return $this->belongsTo(WorkingWithType::class, 'working_with_type_id');
    }

    public function profession()
    {
        return $this->belongsTo(Profession::class, 'profession_id');
    }

    public function occupationMaster(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OccupationMaster::class, 'occupation_master_id');
    }

    public function occupationCustom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OccupationCustom::class, 'occupation_custom_id');
    }

    public function fatherOccupationMaster(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OccupationMaster::class, 'father_occupation_master_id');
    }

    public function fatherOccupationCustom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OccupationCustom::class, 'father_occupation_custom_id');
    }

    public function motherOccupationMaster(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OccupationMaster::class, 'mother_occupation_master_id');
    }

    public function motherOccupationCustom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OccupationCustom::class, 'mother_occupation_custom_id');
    }

    public function incomeRange()
    {
        return $this->belongsTo(IncomeRange::class, 'income_range_id');
    }

    public function college()
    {
        return $this->belongsTo(College::class, 'college_id');
    }

    public function extendedValues()
    {
        return $this->hasMany(\App\Models\ProfileExtendedField::class, 'profile_id');
    }

    public function children()
    {
        return $this->hasMany(\App\Models\ProfileChild::class, 'profile_id');
    }

    public function educationHistory()
    {
        return $this->hasMany(\App\Models\ProfileEducation::class, 'profile_id');
    }

    public function career()
    {
        return $this->hasMany(\App\Models\ProfileCareer::class, 'profile_id');
    }

    public function addresses()
    {
        return $this->hasMany(\App\Models\ProfileAddress::class, 'profile_id');
    }

    public function relatives()
    {
        return $this->hasMany(\App\Models\ProfileRelative::class, 'profile_id');
    }

    public function allianceNetworks()
    {
        return $this->hasMany(\App\Models\ProfileAllianceNetwork::class, 'profile_id');
    }

    public function siblings()
    {
        return $this->hasMany(\App\Models\ProfileSibling::class, 'profile_id');
    }

    public function horoscope()
    {
        return $this->hasOne(\App\Models\ProfileHoroscopeData::class, 'profile_id');
    }

    public $timestamps = true;

    /**
     * Model-level governance seal: prevent update/save from bypassing locks and conflict detection.
     */
    protected static function booted(): void
    {
        static::updating(function (MatrimonyProfile $model) {
            self::enforceGovernanceOnUpdate($model);
        });

        static::saving(function (MatrimonyProfile $model) {
            if ($model->exists) {
                self::enforceGovernanceOnUpdate($model);
            }
        });
    }

    /** Bypass model-level governance (e.g. ConflictResolutionService applying resolution). */
    public static bool $bypassGovernanceEnforcement = false;

    /**
     * Enforce lock check and conflict detection on dirty governed fields. Throws on violation.
     */
    private static function enforceGovernanceOnUpdate(MatrimonyProfile $model): void
    {
        if (self::$bypassGovernanceEnforcement) {
            return;
        }

        $dirty = $model->getDirty();
        if (empty($dirty)) {
            return;
        }

        $governedDirty = array_intersect_key($dirty, array_flip(self::GOVERNED_CORE_KEYS));
        if (empty($governedDirty)) {
            return;
        }

        foreach (array_keys($governedDirty) as $fieldKey) {
            if (ProfileFieldLockService::isLocked($model, $fieldKey)) {
                throw ValidationException::withMessages([
                    $fieldKey => ["Field \"{$fieldKey}\" is locked and cannot be modified."],
                ]);
            }
        }

        $clone = new self;
        $clone->setRawAttributes($model->getOriginal());
        $clone->id = $model->id;
        $clone->exists = true;

        $proposedCore = array_intersect_key($dirty, array_flip(self::GOVERNED_CORE_KEYS));
        $created = ConflictDetectionService::detect($clone, $proposedCore, []);

        if (count($created) > 0) {
            throw ValidationException::withMessages([
                'lifecycle_state' => [
                    'Governance: conflicting change detected. '.count($created).' conflict(s) created. Direct overwrite is not allowed.',
                ],
            ]);
        }
    }

    public function marriages()
    {
        return $this->hasMany(ProfileMarriage::class, 'profile_id');
    }
}
