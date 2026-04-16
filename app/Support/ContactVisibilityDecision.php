<?php

namespace App\Support;

/**
 * Outcome of {@see \App\Services\ContactVisibilityPolicyService::resolveContactAccess}.
 */
enum ContactVisibilityDecision: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case RequiresApproval = 'requires_approval';

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }

    public function requiresApproval(): bool
    {
        return $this === self::RequiresApproval;
    }
}
