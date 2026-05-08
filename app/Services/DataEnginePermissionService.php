<?php

namespace App\Services;

use App\Models\User;

class DataEnginePermissionService
{
    public function canView(User $user): bool
    {
        return $user->isAnyAdmin();
    }

    public function canOperate(User $user): bool
    {
        return $user->hasAdminRole(['super_admin', 'data_admin']);
    }

    public function canReview(User $user): bool
    {
        return $user->hasAdminRole(['super_admin', 'data_admin', 'auditor']);
    }

    public function canApprove(User $user): bool
    {
        return $user->hasAdminRole(['super_admin', 'data_admin']);
    }

    public function canDestructive(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}

