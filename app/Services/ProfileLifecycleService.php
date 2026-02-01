<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Phase-3 Day 7: Centralized profile lifecycle governance.
 * Admin/User initiated transitions ONLY. No event-driven or automatic transitions.
 */
class ProfileLifecycleService
{
    /** Allowed transitions: from => [to, ...] */
    private const ALLOWED_TRANSITIONS = [
        'Draft' => ['Active', 'Archived'],
        'Active' => ['Search-Hidden', 'Suspended', 'Archived', 'Demo-Hidden'],
        'Search-Hidden' => ['Active', 'Suspended', 'Archived'],
        'Suspended' => ['Active', 'Archived'],
        'Archived' => ['Active'],
        'Demo-Hidden' => ['Active', 'Archived'],
    ];

    public static function getAllowedTargets(string $currentState): array
    {
        $current = $currentState ?: 'Active';
        return self::ALLOWED_TRANSITIONS[$current] ?? [];
    }

    public static function canTransitionTo(MatrimonyProfile $profile, string $targetState): bool
    {
        $current = $profile->lifecycle_state ?? 'Active';
        $allowed = self::getAllowedTargets($current);
        return in_array($targetState, $allowed, true);
    }

    /**
     * Transition profile to target state. Throws ValidationException on illegal transition.
     */
    public static function transitionTo(MatrimonyProfile $profile, string $targetState, User $actor): void
    {
        $current = $profile->lifecycle_state ?? 'Active';
        if ($current === $targetState) {
            return;
        }
        if (!self::canTransitionTo($profile, $targetState)) {
            throw ValidationException::withMessages([
                'lifecycle_state' => ["Cannot transition from {$current} to {$targetState}. Allowed: " . implode(', ', self::getAllowedTargets($current))],
            ]);
        }
        $profile->update(['lifecycle_state' => $targetState]);
    }

    /** Visibility: Archived/Suspended/Demo-Hidden (lifecycle_state) → not visible to others. Backward compat: is_suspended, trashed() */
    public static function isVisibleToOthers(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'Active';
        if (in_array($state, ['Archived', 'Suspended', 'Demo-Hidden'], true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    /** Edit: Archived/Suspended → blocked. Backward compat: is_suspended, trashed() */
    public static function isEditable(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'Active';
        if (in_array($state, ['Archived', 'Suspended'], true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    /** Sender: Draft/Archived/Suspended/Demo-Hidden → cannot initiate (send interest, shortlist). Backward compat: is_suspended, trashed() */
    public static function canInitiateInteraction(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'Active';
        if (in_array($state, ['Draft', 'Archived', 'Suspended', 'Demo-Hidden'], true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    /** Interest: Draft/Archived/Suspended/Demo-Hidden → blocked. Backward compat: is_suspended, trashed() */
    public static function canReceiveInterest(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'Active';
        if (in_array($state, ['Draft', 'Archived', 'Suspended', 'Demo-Hidden'], true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    public static function getStates(): array
    {
        return array_keys(self::ALLOWED_TRANSITIONS);
    }
}
