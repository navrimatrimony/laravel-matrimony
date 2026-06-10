<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakActivityLog;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SuchakRepresentationShutdownService
{
    private const SHUTDOWN_ELIGIBLE_STATUSES = [
        SuchakProfileRepresentation::STATUS_PENDING,
        SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
        SuchakProfileRepresentation::STATUS_ACTIVE,
    ];

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * Marks Suchak-side representations as candidate-deactivated after the canonical
     * profile has already been made inactive through the governed profile layer.
     *
     * @return Collection<int, SuchakProfileRepresentation>
     */
    public function markCandidateDeactivated(
        MatrimonyProfile $profile,
        ?User $actor = null,
        ?int $adminAuditLogId = null,
        ?string $reason = null,
    ): Collection {
        $profile->refresh();

        if ($this->profileIsStillActive($profile)) {
            throw new InvalidArgumentException('Candidate profile must be inactive before Suchak representations can be candidate-deactivated.');
        }

        return DB::transaction(function () use ($profile, $actor, $adminAuditLogId, $reason): Collection {
            $deactivatedAt = now();

            $representations = SuchakProfileRepresentation::query()
                ->where('matrimony_profile_id', $profile->id)
                ->whereNull('candidate_deactivated_at')
                ->whereIn('representation_status', self::SHUTDOWN_ELIGIBLE_STATUSES)
                ->lockForUpdate()
                ->get();

            return $representations->map(function (SuchakProfileRepresentation $representation) use ($profile, $actor, $adminAuditLogId, $reason, $deactivatedAt): SuchakProfileRepresentation {
                $previousStatus = $representation->representation_status;
                $previousConsentStatus = $representation->consent_status;

                SuchakProfileRepresentation::query()
                    ->whereKey($representation->id)
                    ->update([
                        'representation_status' => SuchakProfileRepresentation::STATUS_CANDIDATE_DEACTIVATED,
                        'candidate_deactivated_at' => $deactivatedAt,
                    ]);

                $updated = $representation->fresh(['suchakAccount', 'matrimonyProfile']);
                $this->recordShutdownActivity(
                    $updated,
                    $profile,
                    $actor,
                    $adminAuditLogId,
                    $previousStatus,
                    $previousConsentStatus,
                    $reason,
                );

                return $updated;
            });
        });
    }

    private function profileIsStillActive(MatrimonyProfile $profile): bool
    {
        return ($profile->lifecycle_state ?? null) === 'active'
            && (bool) ($profile->is_suspended ?? false) === false;
    }

    private function recordShutdownActivity(
        SuchakProfileRepresentation $representation,
        MatrimonyProfile $profile,
        ?User $actor,
        ?int $adminAuditLogId,
        string $previousStatus,
        string $previousConsentStatus,
        ?string $reason,
    ): void {
        $actorType = $this->actorType($actor);

        $this->activityLogger->record([
            'suchak_account_id' => $representation->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action_type' => SuchakActivityLog::ACTION_REPRESENTATION_CANDIDATE_DEACTIVATED,
            'target_type' => 'suchak_profile_representation',
            'target_id' => $representation->id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'admin_audit_log_id' => $actorType === SuchakActivityLog::ACTOR_ADMIN ? $adminAuditLogId : null,
            'metadata_json' => [
                'context' => 'candidate_deactivated',
                'previous_representation_status' => $previousStatus,
                'new_representation_status' => $representation->representation_status,
                'previous_consent_status' => $previousConsentStatus,
                'consent_status' => $representation->consent_status,
                'profile_lifecycle_state' => $profile->lifecycle_state,
                'profile_is_suspended' => (bool) ($profile->is_suspended ?? false),
                'has_reason' => trim((string) ($reason ?? '')) !== '',
            ],
        ]);
    }

    private function actorType(?User $actor): string
    {
        if ($actor === null) {
            return SuchakActivityLog::ACTOR_SYSTEM;
        }

        return (bool) ($actor->is_admin ?? false)
            ? SuchakActivityLog::ACTOR_ADMIN
            : SuchakActivityLog::ACTOR_USER;
    }
}
