<?php

namespace App\Models;

use App\Services\ConflictDetectionService;
use App\Services\ProfileFieldLockService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    /** Core field keys subject to lock + conflict governance (must match ConflictDetectionService). */
    private const GOVERNED_CORE_KEYS = [
        'full_name',
        'gender',
        'date_of_birth',
        'marital_status',
        'education',
        'location',
        'caste',
        'height_cm',
        'profile_photo',
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
        'gender',
        'date_of_birth',
        'marital_status',
        'caste',
        'education',
        'country_id',
        'state_id',
        'district_id',
        'taluka_id',
        'city_id',
        'height_cm',
        'profile_photo',
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
        'lifecycle_state',
        'profile_visibility_mode',
        'contact_number',
        'contact_visible_to',
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
        'contact_visible_to' => 'array',
        'safety_defaults_applied' => 'boolean',
    ];
    

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

    public function seriousIntent()
    {
        return $this->belongsTo(SeriousIntent::class);
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
