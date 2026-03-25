<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserEntitlementService
{
    public const ENTITLEMENT_CHAT_IMAGE_MESSAGES = 'chat_image_messages';

    public static function userHasEntitlement(User $user, string $key): bool
    {
        return DB::table('user_entitlements')
            ->where('user_id', $user->id)
            ->where('entitlement_key', $key)
            ->whereNull('revoked_at')
            ->where(function ($qb) {
                $qb->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->exists();
    }
}

