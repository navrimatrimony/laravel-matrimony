<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'location',
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
    ];

    protected $casts = [
        'is_suspended' => 'boolean',
        'photo_approved' => 'boolean',
        'photo_rejected_at' => 'datetime',
        'is_demo' => 'boolean',
        'visibility_override' => 'boolean',
        'edited_at' => 'datetime',
        'admin_edited_fields' => 'array',
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
	public $timestamps = true;

}
