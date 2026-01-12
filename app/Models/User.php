<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/*
|--------------------------------------------------------------------------
| User Model
|--------------------------------------------------------------------------
| Purpose:
| - Represents a registered user
| - Handles authentication (login / register)
| - Parent of matrimony profile
| 👉 User = authentication only
| 👉 Matrimony data कधीही इथे ठेवायचा नाही
*/
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
	

    /*
    |--------------------------------------------------------------------------
    | Mass Assignable Fields
    |--------------------------------------------------------------------------
    */
	    /*
    |--------------------------------------------------------------------------
    | Fillable fields
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'email',
        'password',
        'gender',
    ];

    /*
    |--------------------------------------------------------------------------
    | Hidden Fields
    |--------------------------------------------------------------------------
    */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /*
    |--------------------------------------------------------------------------
    | User → Matrimony Profile (ONE TO ONE)
    |--------------------------------------------------------------------------
    | profiles.user_id → users.id
    */
        /*
    |--------------------------------------------------------------------------
    | Relationship: User → MatrimonyProfile
    |--------------------------------------------------------------------------
    |
    | 👉 हा user चा MATRIMONY BIODATA relation आहे
    | 👉 Authentication-related ProfileController शी याचा संबंध नाही
    |
    | वापर:
    | $user->matrimonyProfile
    |
    | लक्षात ठेव:
    | $user->profile ❌ (BAN)
    | $user->matrimonyProfile ✅ (ONLY ALLOWED)
    |
    */
    public function matrimonyProfile()
    {
        return $this->hasOne(\App\Models\MatrimonyProfile::class);
    }

}
