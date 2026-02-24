<?php

namespace App\Services;

use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\FieldValueHistoryService;
use Illuminate\Validation\ValidationException;

/**
 * Phase-3 Day 7: Centralized profile lifecycle governance.
 * Uses ONLY MatrimonyProfile::LIFECYCLE_STATES and MatrimonyProfile::LIFECYCLE_TRANSITIONS.
 * Admin/User initiated transitions ONLY. No event-driven or automatic transitions.
 */
class ProfileLifecycleService
{
    public static function getAllowedTargets(string $currentState): array
    {
        $current = $currentState ?: 'active';
        return MatrimonyProfile::LIFECYCLE_TRANSITIONS[$current] ?? [];
    }

    public static function canTransitionTo(MatrimonyProfile $profile, string $targetState): bool
    {
        $current = $profile->lifecycle_state ?? 'active';
        $allowed = self::getAllowedTargets($current);
        return in_array($targetState, $allowed, true);
    }

    /**
     * Transition profile to target state. Throws ValidationException on illegal transition.
     */
    public static function transitionTo(MatrimonyProfile $profile, string $targetState, User $actor): void
    {
        $current = $profile->lifecycle_state ?? 'active';
        if ($current === $targetState) {
            return;
        }
        if (!self::canTransitionTo($profile, $targetState)) {
            throw ValidationException::withMessages([
                'lifecycle_state' => ["Cannot transition from {$current} to {$targetState}. Allowed: " . implode(', ', self::getAllowedTargets($current))],
            ]);
        }
        $changedBy = ($actor->is_admin ?? false) ? FieldValueHistoryService::CHANGED_BY_ADMIN : FieldValueHistoryService::CHANGED_BY_USER;
        FieldValueHistoryService::record($profile->id, 'lifecycle_state', 'CORE', $current, $targetState, $changedBy);
        $profile->lifecycle_state = $targetState;
        $profile->save();
    }

    /** States (from model) that are not visible to others. Backward compat: is_suspended, trashed() */
    private static function statesNotVisibleToOthers(): array
    {
        return array_values(array_intersect(MatrimonyProfile::LIFECYCLE_STATES, [
            'draft',
            'suspended',
            'archived',
            'archived_due_to_marriage',
        ]));
    }

    /** States (from model) that are not editable. Backward compat: is_suspended, trashed() */
    private static function statesNotEditable(): array
    {
        return array_values(array_intersect(MatrimonyProfile::LIFECYCLE_STATES, [
            'archived',
            'suspended',
        ]));
    }

    /** States (from model) that cannot initiate interaction. Backward compat: is_suspended, trashed() */
    private static function statesCannotInitiateInteraction(): array
    {
        return array_values(array_intersect(MatrimonyProfile::LIFECYCLE_STATES, [
            'draft',
            'archived',
            'suspended',
            'archived_due_to_marriage',
        ]));
    }

    /** States (from model) that cannot receive interest. Backward compat: is_suspended, trashed() */
    private static function statesCannotReceiveInterest(): array
    {
        return array_values(array_intersect(MatrimonyProfile::LIFECYCLE_STATES, [
            'draft',
            'archived',
            'suspended',
            'archived_due_to_marriage',
        ]));
    }

    /** Visibility: not visible if lifecycle_state in non-visible set. Backward compat: is_suspended, trashed() */
    public static function isVisibleToOthers(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'active';
        if (in_array($state, self::statesNotVisibleToOthers(), true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    /** Edit: not editable if lifecycle_state in non-editable set. Backward compat: is_suspended, trashed() */
    public static function isEditable(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'active';
        if (in_array($state, self::statesNotEditable(), true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    /**
     * Manual edit (full form): allowed when lifecycle_state is 'active' or 'draft', or when
     * lifecycle is 'conflict_pending' but only SYSTEM-source conflicts exist (SYSTEM conflicts do not block).
     * Blocks: intake_uploaded, awaiting_user_approval, approved_pending_mutation, and conflict_pending
     * when there is at least one PENDING conflict with source != 'SYSTEM'.
     */
    public static function isEditableForManual(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'active';
        if (in_array($state, ['active', 'draft'], true)) {
            return true;
        }
        if ($state === 'conflict_pending') {
            return ! self::hasBlockingUnresolvedConflicts($profile);
        }
        return false;
    }

    /**
     * True when profile has at least one PENDING conflict with source other than SYSTEM.
     * SYSTEM-generated conflicts do not block user editing; INTAKE/OCR/USER/ADMIN etc. do.
     */
    public static function hasBlockingUnresolvedConflicts(MatrimonyProfile $profile): bool
    {
        return ConflictRecord::where('profile_id', $profile->id)
            ->where('resolution_status', 'PENDING')
            ->where('source', '!=', 'SYSTEM')
            ->exists();
    }

    /**
     * Sync profile lifecycle_state from conflict_records (single source of truth).
     * Call after conflict creation or resolution. Do NOT set lifecycle from MutationService/controllers.
     * - If pending count > 0 and current state is not protected → set conflict_pending.
     * - If pending count === 0 and current state is conflict_pending → set active.
     * Protected states (never overwrite with conflict_pending): intake_uploaded, awaiting_user_approval, approved_pending_mutation.
     */
    public static function syncLifecycleFromPendingConflicts(MatrimonyProfile $profile): void
    {
        $profile->refresh();
        $pending = ConflictRecord::where('profile_id', $profile->id)
            ->where('resolution_status', 'PENDING')
            ->count();
        $current = $profile->lifecycle_state ?? 'active';
        $protectedStates = ['intake_uploaded', 'awaiting_user_approval', 'approved_pending_mutation'];

        if ($pending > 0) {
            if (! in_array($current, $protectedStates, true) && $current !== 'conflict_pending') {
                FieldValueHistoryService::record($profile->id, 'lifecycle_state', 'CORE', $current, 'conflict_pending', FieldValueHistoryService::CHANGED_BY_SYSTEM);
                $profile->lifecycle_state = 'conflict_pending';
                $profile->save();
            }
        } else {
            if ($current === 'conflict_pending') {
                FieldValueHistoryService::record($profile->id, 'lifecycle_state', 'CORE', $current, 'active', FieldValueHistoryService::CHANGED_BY_SYSTEM);
                $profile->lifecycle_state = 'active';
                $profile->save();
            }
        }
    }

    /** Sender: cannot initiate if lifecycle_state in non-initiator set. Backward compat: is_suspended, trashed() */
    public static function canInitiateInteraction(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'active';
        if (in_array($state, self::statesCannotInitiateInteraction(), true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    /** Interest: cannot receive if lifecycle_state in non-receiver set. Backward compat: is_suspended, trashed() */
    public static function canReceiveInterest(MatrimonyProfile $profile): bool
    {
        if ($profile->trashed()) {
            return false;
        }
        $state = $profile->lifecycle_state ?? 'active';
        if (in_array($state, self::statesCannotReceiveInterest(), true)) {
            return false;
        }
        if ($profile->is_suspended ?? false) {
            return false;
        }
        return true;
    }

    public static function getStates(): array
    {
        return MatrimonyProfile::LIFECYCLE_STATES;
    }
}
