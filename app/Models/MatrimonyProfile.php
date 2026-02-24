<?php

namespace App\Models;

use App\Services\ConflictDetectionService;
use App\Services\ProfileFieldLockService;
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
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'highest_education',
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
        'native_city_id',
        'native_taluka_id',
        'native_district_id',
        'native_state_id',
        'height_cm',
        'weight_kg',
        'profile_photo',
        'complexion_id',
        'physical_build_id',
        'blood_group_id',
        'family_type_id',
        'income_currency_id',
        'is_suspended',
        'photo_approved',
        'photo_rejected_at',
        'photo_rejection_reason',
        'is_demo',
        'visibility_override',
        'visibility_override_reason',
        'edited_by',
        'edited_at',
        'edit_reason',
        'edited_source',
        'admin_edited_fields',
        'profile_visibility_mode',
        'contact_unlock_mode',
        'safety_defaults_applied',
        'serious_intent_id',
    ];

    protected $casts = [
        'is_suspended' => 'boolean',
        'photo_approved' => 'boolean',
        'photo_rejected_at' => 'datetime',
        'is_demo' => 'boolean',
        'visibility_override' => 'boolean',
        'edited_at' => 'datetime',
        'admin_edited_fields' => 'array',
        'safety_defaults_applied' => 'boolean',
    ];

    /**
     * Primary contact number from profile_contacts (relation-based). No direct column.
     */
    public function getPrimaryContactNumberAttribute(): ?string
    {
        $phone = DB::table('profile_contacts')
            ->where('profile_id', $this->id)
            ->where('is_primary', true)
            ->value('phone_number');

        return $phone !== null ? (string) $phone : null;
    }

    /**
     * Legacy accessor: contact_number now sourced from primary profile_contacts.
     */
    public function getContactNumberAttribute(): ?string
    {
        return $this->primary_contact_number;
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

    public function familyType()
    {
        return $this->belongsTo(MasterFamilyType::class, 'family_type_id');
    }

    public function incomeCurrency()
    {
        return $this->belongsTo(MasterIncomeCurrency::class, 'income_currency_id');
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

        $clone = new self();
        $clone->setRawAttributes($model->getOriginal());
        $clone->id = $model->id;
        $clone->exists = true;

        $proposedCore = array_intersect_key($dirty, array_flip(self::GOVERNED_CORE_KEYS));
        $created = ConflictDetectionService::detect($clone, $proposedCore, []);

        if (count($created) > 0) {
            throw ValidationException::withMessages([
                'lifecycle_state' => [
                    'Governance: conflicting change detected. ' . count($created) . ' conflict(s) created. Direct overwrite is not allowed.',
                ],
            ]);
        }
    }

}
