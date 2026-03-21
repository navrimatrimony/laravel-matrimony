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
        'mobile',
        'password',
        'gender',
        'mobile_verified_at',
        'registering_for',
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
        'mobile_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
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

    /**
     * Default `matrimony_profiles.full_name` when creating a minimal draft profile.
     * Registrant (`users.name`) maps to profile full name only when `registering_for` is `self`.
     */
    public function defaultBootstrapProfileFullName(): string
    {
        if (($this->registering_for ?? '') !== 'self') {
            return '';
        }

        return trim((string) ($this->name ?? ''));
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Role Helper Methods (Day-7)
    |--------------------------------------------------------------------------
    */

    /**
     * Check if user has any admin role or is legacy admin.
     */
    public function isAnyAdmin(): bool
    {
        return !is_null($this->admin_role) || $this->is_admin === true;
    }

    /**
     * Check if user is super admin (includes legacy is_admin).
     */
    public function isSuperAdmin(): bool
    {
        return $this->admin_role === 'super_admin';
    }

    /**
     * Check if user is data admin.
     */
    public function isDataAdmin(): bool
    {
        return $this->admin_role === 'data_admin';
    }

    /**
     * Check if user is auditor.
     */
    public function isAuditor(): bool
    {
        return $this->admin_role === 'auditor';
    }

    /**
     * Check if user has one of the specified admin roles.
     * Includes legacy is_admin as super_admin.
     */
    public function hasAdminRole(array $roles): bool
    {
        if ($this->is_admin === true && in_array('super_admin', $roles, true)) {
            return true;
        }

        return in_array($this->admin_role, $roles, true);
    }

}
