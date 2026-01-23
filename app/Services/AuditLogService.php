<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\MatrimonyProfile;

/*
|--------------------------------------------------------------------------
| AuditLogService
|--------------------------------------------------------------------------
|
| 👉 Centralized audit logging for admin actions
| 👉 Ensures all admin actions are logged with mandatory reasons
|
*/
class AuditLogService
{
    /**
     * Log an admin action
     *
     * @param User $admin The admin user performing the action
     * @param string $actionType Action type (e.g., 'suspend', 'unsuspend', 'soft_delete', 'image_approve', 'image_reject', 'abuse_resolve')
     * @param string $entityType Entity type (e.g., 'MatrimonyProfile', 'AbuseReport')
     * @param int|null $entityId Entity ID
     * @param string $reason Mandatory reason for the action
     * @param bool $isDemo Whether the action is on a demo profile
     * @return AdminAuditLog
     */
    public static function log(
        User $admin,
        string $actionType,
        string $entityType,
        ?int $entityId,
        string $reason,
        bool $isDemo = false
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'admin_id' => $admin->id,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reason' => $reason,
            'is_demo' => $isDemo,
        ]);
    }
}