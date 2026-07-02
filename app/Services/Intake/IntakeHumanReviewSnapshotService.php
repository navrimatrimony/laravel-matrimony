<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IntakeHumanReviewSnapshotService
{
    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_PROFILE_USER = 'profile_user';

    public const ACTOR_SUCHAK = 'suchak';

    public const ACTOR_SYSTEM = 'system';

    public const SURFACE_ADMIN_PANEL = 'admin_panel';

    public const SURFACE_MOBILE_APP = 'mobile_app';

    public const SURFACE_WEBSITE = 'website';

    public const SURFACE_API = 'api';

    public const POLICY_PHASE2A_HUMAN_REVIEW_V1 = 'phase2a_human_review_v1';

    public const POLICY_PHASE2C_PROFILE_USER_REVIEW_V1 = 'phase2c_profile_user_review_v1';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    /** @var list<string> */
    private const ALLOWED_ACTOR_TYPES = [
        self::ACTOR_ADMIN,
        self::ACTOR_PROFILE_USER,
        self::ACTOR_SUCHAK,
        self::ACTOR_SYSTEM,
    ];

    /** @var list<string> */
    private const ALLOWED_REVIEW_SURFACES = [
        self::SURFACE_ADMIN_PANEL,
        self::SURFACE_MOBILE_APP,
        self::SURFACE_WEBSITE,
        self::SURFACE_API,
    ];

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extraAttributes
     */
    public function saveReviewedSnapshot(
        BiodataIntake $intake,
        array $snapshot,
        array $context,
        array $extraAttributes = [],
    ): BiodataIntake {
        return DB::transaction(function () use ($intake, $snapshot, $context, $extraAttributes): BiodataIntake {
            $locked = BiodataIntake::query()
                ->whereKey($intake->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill(array_merge(
                $this->attributesForSnapshot($snapshot, $context),
                $extraAttributes,
            ))->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function attributesForSnapshot(array $snapshot, array $context): array
    {
        $context = $this->contextWithDefaults($context);

        return [
            'approval_snapshot_json' => $snapshot,
            'reviewed_by_user_id' => $this->nullableInt($context['reviewed_by_user_id'] ?? null),
            'review_actor_type' => $this->actorType($context['review_actor_type'] ?? null),
            'review_surface' => $this->reviewSurface($context['review_surface'] ?? null),
            'reviewed_at' => $context['reviewed_at'] ?? now(),
            'approval_policy' => $this->nullableString($context['approval_policy'] ?? null),
            'approval_status' => $this->nullableString($context['approval_status'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function contextWithDefaults(array $context, array $defaults = []): array
    {
        return array_merge([
            'approval_policy' => self::POLICY_PHASE2A_HUMAN_REVIEW_V1,
            'approval_status' => self::STATUS_REVIEWED,
        ], $defaults, $context);
    }

    private function actorType(mixed $value): ?string
    {
        $actorType = $this->nullableString($value);
        if ($actorType === null) {
            return null;
        }
        if (! in_array($actorType, self::ALLOWED_ACTOR_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported intake review actor type.');
        }

        return $actorType;
    }

    private function reviewSurface(mixed $value): ?string
    {
        $surface = $this->nullableString($value);
        if ($surface === null) {
            return null;
        }
        if (! in_array($surface, self::ALLOWED_REVIEW_SURFACES, true)) {
            throw new InvalidArgumentException('Unsupported intake review surface.');
        }

        return $surface;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
