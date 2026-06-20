<?php

namespace App\Models;

use App\Casts\MojibakeSafeUtf8String;
use App\Services\ConflictDetectionService;
use App\Services\Location\LocationFormatterService;
use App\Services\Location\LocationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\Profile\ProfileTypedSelfAddressService;
use App\Services\ProfileFieldLockService;
use App\Support\Utf8MojibakeRepair;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

/**
 * @property int|null $location_id Canonical residence ({@see Location} / {@code addresses}.id).
 */
class MatrimonyProfile extends Model
{
    /** @var array<string, bool> */
    private static array $columnPresenceCache = [];

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
        'property_details',
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
        'location_id',
        'address_line',
        'birth_city_id',
        'birth_place_text',
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
        'company_name',
        'work_location_text',
        'annual_income',
        'occupation_master_id',
        'occupation_custom_id',
        'income_range_id',
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
        'mother_name',
        'mother_occupation',
        'mother_occupation_master_id',
        'mother_occupation_custom_id',
        'mother_extra_info',
        'mother_contact_1',
        'mother_contact_2',
        // brothers_count, sisters_count: deprecated; use Siblings engine (profile_siblings).

        // Other Relatives engine (इतर नातेवाईक — आडनाव/गाव)
        'other_relatives_text',
        'property_details',

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
        // UTF-8 bytes misread as Latin-1 (mojibake) — repair on read/write for MR/EN narrative fields.
        'full_name' => MojibakeSafeUtf8String::class,
        'highest_education' => MojibakeSafeUtf8String::class,
        'birth_place_text' => MojibakeSafeUtf8String::class,
        'photo_rejection_reason' => MojibakeSafeUtf8String::class,
        'photo_moderation_snapshot' => 'array',
        'visibility_override_reason' => MojibakeSafeUtf8String::class,
        'edit_reason' => MojibakeSafeUtf8String::class,
        'company_name' => MojibakeSafeUtf8String::class,
        'annual_income' => MojibakeSafeUtf8String::class,
        'family_income' => MojibakeSafeUtf8String::class,
        'father_name' => MojibakeSafeUtf8String::class,
        'father_occupation' => MojibakeSafeUtf8String::class,
        'father_extra_info' => MojibakeSafeUtf8String::class,
        'father_contact_1' => MojibakeSafeUtf8String::class,
        'father_contact_2' => MojibakeSafeUtf8String::class,
        'mother_name' => MojibakeSafeUtf8String::class,
        'mother_occupation' => MojibakeSafeUtf8String::class,
        'mother_extra_info' => MojibakeSafeUtf8String::class,
        'mother_contact_1' => MojibakeSafeUtf8String::class,
        'mother_contact_2' => MojibakeSafeUtf8String::class,
        'other_relatives_text' => MojibakeSafeUtf8String::class,
        'property_details' => MojibakeSafeUtf8String::class,
        'physical_condition' => MojibakeSafeUtf8String::class,
        'weight_range' => MojibakeSafeUtf8String::class,
    ];

    /**
     * When {@code matrimony_profiles.location_id} / {@code address_line} columns are absent, mass-assignment
     * on create is flushed into {@code profile_addresses} on first {@see saved} (profile id required).
     *
     * @var array<string, mixed>
     */
    protected array $pendingCanonicalSelfResidence = [];

    protected function locationId(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?int {
                if (self::hasColumnCached($this->getTable(), 'location_id')) {
                    if ($value === null || $value === '') {
                        return null;
                    }

                    return (int) $value;
                }
                if (! $this->exists) {
                    return null;
                }

                return ProfileCanonicalResidenceService::locationLeafId((int) $this->id);
            },
            set: function ($value): void {
                $intVal = $value === null || $value === '' ? null : (int) $value;
                if (self::hasColumnCached($this->getTable(), 'location_id')) {
                    $this->attributes['location_id'] = $intVal;

                    return;
                }
                if (! $this->exists) {
                    $this->pendingCanonicalSelfResidence['city'] = $intVal;

                    return;
                }
                ProfileCanonicalResidenceService::upsertSelfCurrent((int) $this->id, $intVal, null, true, false);
            },
        );
    }

    protected function addressLine(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                $raw = null;
                if (self::hasColumnCached($this->getTable(), 'address_line')) {
                    $raw = $this->attributes['address_line'] ?? null;
                } elseif ($this->exists) {
                    $raw = ProfileCanonicalResidenceService::addressLineRaw((int) $this->id);
                }
                if ($raw === null) {
                    return null;
                }
                $repaired = Utf8MojibakeRepair::repair((string) $raw);

                return is_string($repaired) ? $repaired : (string) $raw;
            },
            set: function ($value): void {
                $normalized = null;
                if ($value !== null && trim((string) $value) !== '') {
                    $s = is_string($value) ? $value : (string) $value;
                    $repaired = Utf8MojibakeRepair::repair($s);
                    $normalized = is_string($repaired) ? $repaired : $s;
                }
                if (self::hasColumnCached($this->getTable(), 'address_line')) {
                    $this->attributes['address_line'] = $normalized;

                    return;
                }
                if (! $this->exists) {
                    $this->pendingCanonicalSelfResidence['line'] = $normalized;

                    return;
                }
                ProfileCanonicalResidenceService::upsertSelfCurrent((int) $this->id, null, $normalized, false, true);
            },
        );
    }

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

        $genderKey = $this->gender?->key;
        if ($genderKey === 'male') {
            return asset('images/placeholders/male-profile.svg');
        }
        if ($genderKey === 'female') {
            return asset('images/placeholders/female-profile.svg');
        }

        return asset('images/placeholders/default-profile.svg');
    }

    /**
     * Public discovery/card rank: only approved, non-placeholder photos count.
     */
    public function hasApprovedPublicPhoto(): bool
    {
        if ($this->relationLoaded('photos')) {
            foreach ($this->photos as $photo) {
                if (! $photo instanceof ProfilePhoto) {
                    continue;
                }

                $path = ltrim((string) $photo->file_path, '/');
                if (
                    $photo->effectiveApprovedStatus() === 'approved'
                    && $path !== ''
                    && ! \App\Services\Image\ProfilePhotoUrlService::isPendingPlaceholder($path)
                ) {
                    return true;
                }
            }
        } elseif (Schema::hasTable('profile_photos')) {
            foreach (ProfilePhoto::query()
                ->where('profile_id', $this->id)
                ->effectivelyApproved()
                ->get(['file_path']) as $photo) {
                $path = ltrim((string) $photo->file_path, '/');
                if ($path !== '' && ! \App\Services\Image\ProfilePhotoUrlService::isPendingPlaceholder($path)) {
                    return true;
                }
            }
        }

        $legacy = ltrim((string) ($this->profile_photo ?? ''), '/');

        return $legacy !== ''
            && $this->photo_approved !== false
            && ! \App\Services\Image\ProfilePhotoUrlService::isPendingPlaceholder($legacy);
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
     * Residence leaf {@see Location} row is under this ancestor {@code addresses.id} (country/state/district/taluka/city).
     */
    public function scopeWhereResidenceUnderAncestor(Builder $query, int $ancestorAddressId): Builder
    {
        $geo = Location::geoTable();
        $tbl = $this->getTable();

        if (self::hasColumnCached($tbl, 'location_id')) {
            return $query->whereNotNull($tbl.'.location_id')
                ->whereRaw(
                    "EXISTS (
                        WITH RECURSIVE chain AS (
                            SELECT id, parent_id FROM {$geo} WHERE id = {$tbl}.location_id
                            UNION ALL
                            SELECT a.id, a.parent_id FROM {$geo} a INNER JOIN chain c ON a.id = c.parent_id
                        )
                        SELECT 1 FROM chain WHERE chain.id = ?
                    )",
                    [$ancestorAddressId]
                );
        }

        $typeId = ProfileCanonicalResidenceService::currentAddressTypeId();
        if ($typeId === null) {
            return $query->whereRaw('1 = 0');
        }

        $leafCol = self::hasColumnCached('profile_addresses', 'location_id') ? 'pa.location_id' : 'pa.city_id';

        return $query->whereExists(function ($q) use ($tbl, $geo, $ancestorAddressId, $typeId, $leafCol): void {
            $q->selectRaw('1')
                ->from('profile_addresses as pa')
                ->whereColumn('pa.profile_id', $tbl.'.id')
                ->where('pa.address_scope', 'self')
                ->where('pa.address_type_id', $typeId)
                ->whereNotNull($leafCol)
                ->whereRaw(
                    "EXISTS (
                        WITH RECURSIVE chain AS (
                            SELECT id, parent_id FROM {$geo} WHERE id = {$leafCol}
                            UNION ALL
                            SELECT a.id, a.parent_id FROM {$geo} a INNER JOIN chain c ON a.id = c.parent_id
                        )
                        SELECT 1 FROM chain WHERE chain.id = ?
                    )",
                    [$ancestorAddressId]
                );
        });
    }

    /**
     * District / state / country as {@code addresses.id} values derived from {@see location_id} (partner matching, search).
     *
     * @return array{district_id: int|null, state_id: int|null, country_id: int|null}
     */
    public function residenceGeoAddressIds(): array
    {
        $empty = ['district_id' => null, 'state_id' => null, 'country_id' => null];
        if (! $this->location_id || ! Schema::hasTable(Location::geoTable())) {
            return $empty;
        }
        $leaf = Location::query()->find((int) $this->location_id);
        if ($leaf === null) {
            return $empty;
        }
        $svc = app(LocationService::class);
        $h = $svc->getFullHierarchy($leaf);
        $district = $h['district'] ?? null;
        if ($district === null && $leaf->hierarchy === 'district') {
            $district = $leaf;
        }
        $state = $h['state'] ?? null;
        if ($state === null && $leaf->hierarchy === 'state') {
            $state = $leaf;
        }
        $country = $svc->getAncestorByType($leaf, 'country');
        if ($country === null && $leaf->hierarchy === 'country') {
            $country = $leaf;
        }

        return [
            'district_id' => $district !== null ? (int) $district->id : null,
            'state_id' => $state !== null ? (int) $state->id : null,
            'country_id' => $country !== null ? (int) $country->id : null,
        ];
    }

    /**
     * Values for {@see resources/views/components/profile/location-typeahead.blade.php} residence context:
     * ancestor {@code addresses.id} hints derived from {@see location_id} only (not persisted as separate columns).
     *
     * @return array{location_id: string, country_id: string, state_id: string, district_id: string, taluka_id: string}
     */
    public function residenceLocationHierarchyHints(): array
    {
        $empty = ['location_id' => '', 'country_id' => '', 'state_id' => '', 'district_id' => '', 'taluka_id' => ''];
        if (! $this->location_id || ! Schema::hasTable(Location::geoTable())) {
            return $empty;
        }
        $leaf = Location::query()->find((int) $this->location_id);
        if ($leaf === null) {
            return $empty;
        }
        $svc = app(LocationService::class);
        $h = $svc->getFullHierarchy($leaf);
        $country = $svc->getAncestorByType($leaf, 'country');

        return [
            'location_id' => (string) $this->location_id,
            'country_id' => $country ? (string) $country->id : '',
            'state_id' => $h['state'] ? (string) $h['state']->id : '',
            'district_id' => $h['district'] ? (string) $h['district']->id : '',
            'taluka_id' => $h['taluka'] ? (string) $h['taluka']->id : '',
        ];
    }

    /**
     * Same as {@see residenceLocationHierarchyHints()} but for {@see birth_city_id} (birth place leaf).
     *
     * @return array{location_id: string, country_id: string, state_id: string, district_id: string, taluka_id: string}
     */
    public function birthCityHierarchyHints(): array
    {
        $empty = ['location_id' => '', 'country_id' => '', 'state_id' => '', 'district_id' => '', 'taluka_id' => ''];
        if (! $this->birth_city_id || ! Schema::hasTable(Location::geoTable())) {
            return $empty;
        }
        $leaf = Location::query()->find((int) $this->birth_city_id);
        if ($leaf === null) {
            return $empty;
        }
        $svc = app(LocationService::class);
        $h = $svc->getFullHierarchy($leaf);
        $country = $svc->getAncestorByType($leaf, 'country');

        return [
            'location_id' => (string) $this->birth_city_id,
            'country_id' => $country ? (string) $country->id : '',
            'state_id' => $h['state'] ? (string) $h['state']->id : '',
            'district_id' => $h['district'] ? (string) $h['district']->id : '',
            'taluka_id' => $h['taluka'] ? (string) $h['taluka']->id : '',
        ];
    }

    /**
     * Native place leaf: legacy column when present, else self + {@code native} in {@code profile_addresses}.
     */
    public function nativePlaceLeafStorageId(): ?int
    {
        if (self::hasColumnCached($this->getTable(), 'native_city_id')) {
            $v = $this->attributes['native_city_id'] ?? null;

            return $v !== null && $v !== '' && (int) $v > 0 ? (int) $v : null;
        }
        if (! $this->exists) {
            return null;
        }

        return ProfileTypedSelfAddressService::locationLeafIdForSelfType((int) $this->id, 'native');
    }

    /**
     * Work city leaf: legacy column when present, else self + {@code work} in {@code profile_addresses}.
     */
    public function workCityLeafStorageId(): ?int
    {
        if (self::hasColumnCached($this->getTable(), 'work_city_id')) {
            $v = $this->attributes['work_city_id'] ?? null;

            return $v !== null && $v !== '' && (int) $v > 0 ? (int) $v : null;
        }
        if (! $this->exists) {
            return null;
        }

        return ProfileTypedSelfAddressService::locationLeafIdForSelfType((int) $this->id, 'work');
    }

    /**
     * Read-time compatibility when {@code work_city_id} / {@code work_state_id} columns are absent.
     */
    public function getWorkCityIdAttribute(mixed $value): ?int
    {
        return $this->workCityLeafStorageId();
    }

    public function getWorkStateIdAttribute(mixed $value): ?int
    {
        if (self::hasColumnCached($this->getTable(), 'work_state_id')) {
            return ($value !== null && $value !== '') ? (int) $value : null;
        }
        $leaf = $this->workCityLeafStorageId();
        if ($leaf === null || ! Schema::hasTable(Location::geoTable())) {
            return null;
        }
        $row = Location::query()->find($leaf);
        if ($row === null) {
            return null;
        }
        $state = app(LocationService::class)->getAncestorByType($row, 'state');

        return $state?->id ? (int) $state->id : null;
    }

    public function getNativeCityIdAttribute(mixed $value): ?int
    {
        return $this->nativePlaceLeafStorageId();
    }

    /**
     * Same as {@see birthCityHierarchyHints()} for native place leaf.
     *
     * @return array{location_id: string, country_id: string, state_id: string, district_id: string, taluka_id: string}
     */
    public function nativePlaceHierarchyHints(): array
    {
        $empty = ['location_id' => '', 'country_id' => '', 'state_id' => '', 'district_id' => '', 'taluka_id' => ''];
        $leafId = $this->nativePlaceLeafStorageId();
        if (! $leafId || ! Schema::hasTable(Location::geoTable())) {
            return $empty;
        }
        $leaf = Location::query()->find($leafId);
        if ($leaf === null) {
            return $empty;
        }
        $svc = app(LocationService::class);
        $h = $svc->getFullHierarchy($leaf);
        $country = $svc->getAncestorByType($leaf, 'country');

        return [
            'location_id' => (string) $leafId,
            'country_id' => $country ? (string) $country->id : '',
            'state_id' => $h['state'] ? (string) $h['state']->id : '',
            'district_id' => $h['district'] ? (string) $h['district']->id : '',
            'taluka_id' => $h['taluka'] ? (string) $h['taluka']->id : '',
        ];
    }

    /**
     * Same as {@see residenceLocationHierarchyHints()} for work city leaf.
     *
     * @return array{location_id: string, country_id: string, state_id: string, district_id: string, taluka_id: string}
     */
    public function workCityHierarchyHints(): array
    {
        $empty = ['location_id' => '', 'country_id' => '', 'state_id' => '', 'district_id' => '', 'taluka_id' => ''];
        $leafId = $this->workCityLeafStorageId();
        if (! $leafId || ! Schema::hasTable(Location::geoTable())) {
            return $empty;
        }
        $leaf = Location::query()->find($leafId);
        if ($leaf === null) {
            return $empty;
        }
        $svc = app(LocationService::class);
        $h = $svc->getFullHierarchy($leaf);
        $country = $svc->getAncestorByType($leaf, 'country');

        return [
            'location_id' => (string) $leafId,
            'country_id' => $country ? (string) $country->id : '',
            'state_id' => $h['state'] ? (string) $h['state']->id : '',
            'district_id' => $h['district'] ? (string) $h['district']->id : '',
            'taluka_id' => $h['taluka'] ? (string) $h['taluka']->id : '',
        ];
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
     * Partner preference: acceptable qualification degrees ({@see EducationDegree} / {@code master_education}).
     */
    public function preferredEducationDegrees(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            EducationDegree::class,
            'profile_preferred_education_degrees',
            'profile_id',
            'education_degree_id'
        )->withTimestamps();
    }

    /**
     * Partner preference: acceptable occupations ({@see OccupationMaster}).
     */
    public function preferredOccupationMasters(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            OccupationMaster::class,
            'profile_preferred_occupation_master',
            'profile_id',
            'occupation_master_id'
        )->withTimestamps();
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

    public function suchakProfileRepresentations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SuchakProfileRepresentation::class, 'matrimony_profile_id');
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
    | Location — SSOT: self + type "current" in {@code profile_addresses.location_id} (leaf {@code addresses.id}).
    |--------------------------------------------------------------------------
    */

    /**
     * Canonical current residence leaf in the unified {@see Location} table.
     */
    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
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
        $lid = $profileOrRow->location_id ?? null;
        if (! $lid || ! Schema::hasTable(Location::geoTable())) {
            return '';
        }

        return app(LocationFormatterService::class)->formatLocation((int) $lid);
    }

    /**
     * Human-readable current residence line from canonical {@see Location} only.
     */
    public function residenceLocationDisplayLine(): string
    {
        if (! $this->location_id || ! Schema::hasTable(Location::geoTable())) {
            return '';
        }

        return app(LocationFormatterService::class)->formatLocation((int) $this->location_id);
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
        $geo = $this->residenceGeoAddressIds();

        return self::residenceDistrictAndStateLineFromIds(
            $geo['district_id'],
            $geo['state_id'],
        );
    }

    /**
     * Birth place line from {@code addresses} via {@see LocationFormatterService} (same SSOT as residence).
     */
    public function birthLocationDisplayLine(): string
    {
        if ($this->birth_city_id && Schema::hasTable(Location::geoTable())) {
            $line = app(LocationFormatterService::class)->formatLocation((int) $this->birth_city_id);
            if ($line !== '') {
                return $line;
            }
        }

        return trim((string) ($this->birthCity?->localizedName() ?? ''));
    }

    /**
     * Native place from canonical leaf in {@code addresses} (column or typed self-address).
     */
    public function nativeLocationDisplayLine(): string
    {
        $leaf = $this->nativePlaceLeafStorageId();
        if ($leaf && Schema::hasTable(Location::geoTable())) {
            return app(LocationFormatterService::class)->formatLocation((int) $leaf);
        }

        return '';
    }

    /**
     * Work location from canonical work-city leaf ({@code addresses}).
     */
    public function workLocationDisplayLine(): string
    {
        $leaf = $this->workCityLeafStorageId();
        if ($leaf && Schema::hasTable(Location::geoTable())) {
            $line = app(LocationFormatterService::class)->formatLocation((int) $leaf);
            if ($line !== '') {
                return $line;
            }
        }
        if ($this->exists && Schema::hasTable('profile_addresses')) {
            $fallback = ProfileTypedSelfAddressService::addressLineForSelfType((int) $this->id, 'work');
            if ($fallback !== null && $fallback !== '') {
                return $fallback;
            }
        }

        return '';
    }

    public function getWorkLocationTextAttribute(mixed $value): ?string
    {
        if (self::hasColumnCached($this->getTable(), 'work_location_text')) {
            $stored = array_key_exists('work_location_text', $this->attributes)
                ? $this->attributes['work_location_text']
                : null;

            return (new MojibakeSafeUtf8String)->get($this, 'work_location_text', $stored, $this->attributes);
        }
        if (! $this->exists || ! Schema::hasTable('profile_addresses')) {
            return null;
        }

        return ProfileTypedSelfAddressService::addressLineForSelfType((int) $this->id, 'work');
    }

    public function setWorkLocationTextAttribute(mixed $value): void
    {
        if ($value !== null && $value !== '' && ! is_string($value)) {
            $value = (string) $value;
        }
        $prepared = ($value !== null && is_string($value) && trim($value) !== '')
            ? mb_substr(trim($value), 0, 255)
            : null;
        if (self::hasColumnCached($this->getTable(), 'work_location_text')) {
            $arr = (new MojibakeSafeUtf8String)->set($this, 'work_location_text', $prepared, $this->attributes);
            $this->attributes['work_location_text'] = $arr['work_location_text'];

            return;
        }
        if ($this->exists) {
            ProfileTypedSelfAddressService::upsertSelfTypedAddressLine((int) $this->id, 'work', $prepared);
        }
    }

    /** Birth place leaf row in {@code addresses} (hierarchy from {@see Location::parent}). */
    public function birthCity()
    {
        return $this->belongsTo(Location::class, 'birth_city_id');
    }

    /**
     * Legacy parallel hierarchy FKs on {@code matrimony_profiles} → typed rows in {@code addresses}.
     * Nullable when residence is {@see location_id} only (canonical SSOT).
     */
    public function country(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function district(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function taluka(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Taluka::class, 'taluka_id');
    }

    public function city(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /** Birth place hierarchy (optional columns; leaf is {@see birthCity}). */
    public function birthTaluka(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Taluka::class, 'birth_taluka_id');
    }

    public function birthDistrict(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(District::class, 'birth_district_id');
    }

    public function birthState(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(State::class, 'birth_state_id');
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

    /** Best-effort legacy {@see Profession} row — name match on canonical {@see OccupationMaster} only. */
    public function resolvedProfession(): ?Profession
    {
        if (self::hasColumnCached($this->getTable(), 'profession_id') && isset($this->attributes['profession_id']) && $this->attributes['profession_id']) {
            return Profession::query()->find((int) $this->attributes['profession_id']);
        }
        $this->loadMissing(['occupationMaster']);
        $nm = trim((string) ($this->occupationMaster?->name ?? ''));
        if ($nm === '') {
            return null;
        }

        return Profession::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($nm)])
            ->first();
    }

    /** Workplace category via occupation master → {@code master_occupation_categories.legacy_working_with_type_id}. */
    public function resolvedWorkingWithType(): ?WorkingWithType
    {
        if (self::hasColumnCached($this->getTable(), 'working_with_type_id')
            && isset($this->attributes['working_with_type_id'])
            && $this->attributes['working_with_type_id']) {
            return WorkingWithType::query()->find((int) $this->attributes['working_with_type_id']);
        }

        $this->loadMissing(['occupationMaster.category.workingWithType']);

        return $this->occupationMaster?->category?->workingWithType;
    }

    /** Human-readable career title (canonical engine or legacy column when present). */
    public function getOccupationTitleAttribute(mixed $value): ?string
    {
        if (self::hasColumnCached($this->getTable(), 'occupation_title')) {
            $stored = array_key_exists('occupation_title', $this->attributes)
                ? $this->attributes['occupation_title']
                : null;

            return (new MojibakeSafeUtf8String)->get($this, 'occupation_title', $stored, $this->attributes);
        }

        $this->loadMissing(['occupationMaster', 'occupationCustom']);

        $t = trim((string) ($this->occupationMaster?->name ?? $this->occupationCustom?->raw_name ?? ''));

        return $t !== '' ? $t : null;
    }

    public function setOccupationTitleAttribute(mixed $value): void
    {
        if (! self::hasColumnCached($this->getTable(), 'occupation_title')) {
            return;
        }
        $arr = (new MojibakeSafeUtf8String)->set($this, 'occupation_title', $value, $this->attributes);
        $this->attributes['occupation_title'] = $arr['occupation_title'];
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

    public function extendedValues()
    {
        return $this->hasMany(\App\Models\ProfileExtendedField::class, 'profile_id');
    }

    public function visibilitySetting(): HasOne
    {
        return $this->hasOne(ProfileVisibilitySetting::class, 'profile_id');
    }

    public function children()
    {
        return $this->hasMany(\App\Models\ProfileChild::class, 'profile_id');
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
     * When residence columns are dropped, {@see locationId} / {@see addressLine} are virtual — do not emit SQL for them.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = parent::getDirty();
        if (! self::hasColumnCached($this->getTable(), 'location_id')) {
            unset($dirty['location_id']);
        }
        if (! self::hasColumnCached($this->getTable(), 'address_line')) {
            unset($dirty['address_line']);
        }

        return $dirty;
    }

    /**
     * Exclude virtual residence keys from INSERT/UPDATE SQL when the backing columns were removed.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        $attrs = parent::getAttributes();
        if (! self::hasColumnCached($this->getTable(), 'location_id')) {
            unset($attrs['location_id']);
        }
        if (! self::hasColumnCached($this->getTable(), 'address_line')) {
            unset($attrs['address_line']);
        }

        return $attrs;
    }

    private static function hasColumnCached(string $table, string $column): bool
    {
        $key = $table.'|'.$column;
        if (! array_key_exists($key, self::$columnPresenceCache)) {
            self::$columnPresenceCache[$key] = Schema::hasColumn($table, $column);
        }

        return self::$columnPresenceCache[$key];
    }

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

        static::saved(function (MatrimonyProfile $profile): void {
            if (self::hasColumnCached($profile->getTable(), 'location_id')) {
                return;
            }
            if ($profile->pendingCanonicalSelfResidence === []) {
                return;
            }
            $city = array_key_exists('city', $profile->pendingCanonicalSelfResidence)
                ? $profile->pendingCanonicalSelfResidence['city']
                : null;
            $line = array_key_exists('line', $profile->pendingCanonicalSelfResidence)
                ? $profile->pendingCanonicalSelfResidence['line']
                : null;
            $touchCity = array_key_exists('city', $profile->pendingCanonicalSelfResidence);
            $touchLine = array_key_exists('line', $profile->pendingCanonicalSelfResidence);
            $profile->pendingCanonicalSelfResidence = [];
            ProfileCanonicalResidenceService::upsertSelfCurrent(
                (int) $profile->id,
                $city,
                $line,
                $touchCity,
                $touchLine,
            );
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
