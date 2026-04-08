<?php

namespace App\Services;

use App\Models\User;

/**
 * Hard rule: in-app notifications about another member's social/engagement action
 * must not be delivered to recipients when the actor is an admin (staff) account.
 *
 * Does not apply to system/plan notices or moderation outcome notices from the admin panel.
 */
final class AdminActivityNotificationGate
{
    /**
     * @param  User|null  $actor  The user who performed the action (viewer, sender, accepter, etc.)
     */
    public static function allowsPeerActivityNotification(?User $actor): bool
    {
        if ($actor === null) {
            return true;
        }

        return ! $actor->isAnyAdmin();
    }
}
