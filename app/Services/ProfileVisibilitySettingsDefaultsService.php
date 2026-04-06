<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;

/**
 * Inserts {@see profile_visibility_settings} with product defaults when a profile is created.
 */
class ProfileVisibilitySettingsDefaultsService
{
    public static function ensureForProfile(MatrimonyProfile $profile): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('profile_visibility_settings')) {
            return;
        }

        if (DB::table('profile_visibility_settings')->where('profile_id', $profile->id)->exists()) {
            return;
        }

        $payload = ($profile->is_demo ?? false)
            ? self::showcaseDefaults()
            : self::registrationDefaults();

        DB::table('profile_visibility_settings')->insert(array_merge($payload, [
            'profile_id' => $profile->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Normal user registration / wizard: widest contact audience; user still needs plan/quota to reveal.
     *
     * @return array<string, mixed>
     */
    public static function registrationDefaults(): array
    {
        return [
            'visibility_scope' => 'public',
            'show_photo_to' => 'all',
            'show_contact_to' => ContactRevealPolicyService::SHOW_CONTACT_EVERYONE,
            'hide_from_blocked_users' => true,
        ];
    }

    /**
     * Admin showcase / demo profiles: hide contact number + email from direct paid reveal by default.
     *
     * @return array<string, mixed>
     */
    public static function showcaseDefaults(): array
    {
        return [
            'visibility_scope' => 'public',
            'show_photo_to' => 'all',
            'show_contact_to' => ContactRevealPolicyService::SHOW_CONTACT_NO_ONE,
            'hide_from_blocked_users' => true,
        ];
    }
}
