<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persists partner community intent flags (e.g. open to intercaste) outside MutationService preference sync.
 */
final class ProfilePartnerCommunityFlagService
{
    public static function table(): string
    {
        return 'profile_partner_community_flags';
    }

    public static function interestedInIntercaste(int $profileId): bool
    {
        if (! Schema::hasTable(self::table())) {
            return false;
        }

        return (bool) DB::table(self::table())
            ->where('profile_id', $profileId)
            ->value('interested_in_intercaste');
    }

    public static function syncIntercasteIntentFromRequest(int $profileId, Request $request): void
    {
        if (! Schema::hasTable(self::table())) {
            return;
        }
        $value = $request->boolean('preferred_intercaste');
        $now = now();
        $exists = DB::table(self::table())->where('profile_id', $profileId)->exists();
        if ($exists) {
            DB::table(self::table())->where('profile_id', $profileId)->update([
                'interested_in_intercaste' => $value,
                'updated_at' => $now,
            ]);

            return;
        }
        DB::table(self::table())->insert([
            'profile_id' => $profileId,
            'interested_in_intercaste' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
