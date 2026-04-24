<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
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
class User extends Authenticatable implements MustVerifyEmail
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
        'plan',
        'plan_expires_at',
        'plan_status',
        'plan_started_at',
        'mobile',
        'mobile_backup',
        'mobile_duplicate_of_user_id',
        'password',
        'gender',
        'mobile_verified_at',
        'registering_for',
        'referral_code',
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
        'plan_expires_at' => 'datetime',
        'plan_started_at' => 'datetime',
        'mobile_verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_inactive_reminder_sent_at' => 'datetime',
        'last_new_matches_digest_sent_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
        'photo_uploads_suspended' => 'boolean',
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

    public function moderationStat(): HasOne
    {
        return $this->hasOne(UserModerationStat::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function mobileDuplicatePrimary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mobile_duplicate_of_user_id');
    }

    public function mobileDuplicateSecondaries(): HasMany
    {
        return $this->hasMany(User::class, 'mobile_duplicate_of_user_id');
    }

    public function featureUsages(): HasMany
    {
        return $this->hasMany(UserFeatureUsage::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(UserWallet::class);
    }

    public function referralsMade(): HasMany
    {
        return $this->hasMany(UserReferral::class, 'referrer_id');
    }

    public function referralReceived(): HasOne
    {
        return $this->hasOne(UserReferral::class, 'referred_user_id');
    }

    public function profileBoosts(): HasMany
    {
        return $this->hasMany(ProfileBoost::class);
    }

    public function helpCentreTickets(): HasMany
    {
        return $this->hasMany(HelpCentreTicket::class);
    }

    /**
     * Unique uppercase code for shareable referral links (assigned after registration).
     */
    public static function generateUniqueReferralCode(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = strtoupper(Str::random(8));
            if (! static::query()->where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        return strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    }

    /**
     * Relationship helper: subscription with max {@code starts_at} among rows matching {@see Subscription::scopeEffectivelyActiveForAccess()}.
     * For gates, quotas, and meta, prefer imperative {@see \App\Services\SubscriptionService::getActiveSubscription()} (same scope + ordering SSOT).
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->ofMany(
                ['starts_at' => 'max'],
                fn ($q) => $q->effectivelyActiveForAccess()
            );
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
        return ! is_null($this->admin_role) || $this->is_admin === true;
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

    /**
     * Whether the subscription period is currently valid.
     * If past expiry, persists plan_status = expired when it was not already expired.
     */
    public function isPlanActive(): bool
    {
        if ($this->plan_expires_at !== null && $this->plan_expires_at->isPast()) {
            if (($this->plan_status ?? '') !== 'expired') {
                $this->plan_status = 'expired';
                $this->save();
            }

            return false;
        }

        return $this->plan_expires_at !== null && $this->plan_expires_at->isFuture();
    }
}
