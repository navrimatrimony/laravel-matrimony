<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * The marketplace stage ladder (blueprint section 6a) for one engagement.
 *
 * The engagement / assist object is SuchakCollaborationRequest + SuchakCommissionAgreement
 * (blueprint section 6.1) — there is no separate engagements table. One row here per
 * (engagement, ladder stage).
 *
 * STAGE_LADDER is the single ordered vocabulary. Installment triggers must be chosen from it and
 * never written as free text.
 */
class SuchakCollaborationStageEvent extends Model
{
    use HasFactory;

    public const STAGE_REGISTRATION = 'registration';
    public const STAGE_AGREEMENT_PROPOSED = 'agreement_proposed';
    public const STAGE_AGREEMENT_ACCEPTED = 'agreement_accepted';
    public const STAGE_PUBLISHED_TO_MARKETPLACE = 'published_to_marketplace';
    public const STAGE_PROFILE_SUGGESTED = 'profile_suggested';
    public const STAGE_VIEWED = 'viewed';
    public const STAGE_INTERESTED = 'interested';
    public const STAGE_MEETING_SCHEDULED = 'meeting_scheduled';
    public const STAGE_MEETING_COMPLETED = 'meeting_completed';
    public const STAGE_MEETING_CONFIRMED = 'meeting_confirmed';
    public const STAGE_MARRIAGE_SETTLED = 'marriage_settled';
    public const STAGE_ENGAGEMENT = 'engagement';
    public const STAGE_MARRIAGE = 'marriage';
    public const STAGE_SHARE_SETTLED = 'share_settled';

    /**
     * What each stage is called when a person reads it.
     *
     * The vocabulary and its wording live together on purpose: a second map
     * elsewhere would be free to drift, and a success-fee installment shown to a
     * customer under one name and to a Suchak under another is an argument
     * waiting to happen. Marathi only for now — every surface that renders a
     * stage today is Marathi-only, and inventing an English column nobody reads
     * would be inventing a fact.
     *
     * @var array<string, string>
     */
    public const STAGE_LABELS_MR = [
        self::STAGE_REGISTRATION => 'नोंदणी',
        self::STAGE_AGREEMENT_PROPOSED => 'करार पाठवला',
        self::STAGE_AGREEMENT_ACCEPTED => 'करार स्वीकारला',
        self::STAGE_PUBLISHED_TO_MARKETPLACE => 'बाजारपेठेत प्रसिद्ध',
        self::STAGE_PROFILE_SUGGESTED => 'स्थळ सुचवले',
        self::STAGE_VIEWED => 'स्थळ पाहिले',
        self::STAGE_INTERESTED => 'पसंती दर्शवली',
        self::STAGE_MEETING_SCHEDULED => 'भेट ठरली',
        self::STAGE_MEETING_COMPLETED => 'भेट झाली',
        self::STAGE_MEETING_CONFIRMED => 'भेटीला दुजोरा',
        self::STAGE_MARRIAGE_SETTLED => 'लग्न ठरल्यावर',
        self::STAGE_ENGAGEMENT => 'साखरपुड्यानंतर',
        self::STAGE_MARRIAGE => 'विवाहानंतर',
        self::STAGE_SHARE_SETTLED => 'वाटा दिल्यावर',
    ];

    /**
     * Falls back to the raw key rather than to an empty string: an unlabelled
     * stage on a screen is a bug someone can see and report, a blank one is not.
     */
    public static function stageLabel(string $stageKey): string
    {
        return self::STAGE_LABELS_MR[$stageKey] ?? $stageKey;
    }

    /**
     * The ladder, in order. Iterate this — do not re-declare the sequence anywhere else.
     *
     * @var list<string>
     */
    public const STAGE_LADDER = [
        self::STAGE_REGISTRATION,
        self::STAGE_AGREEMENT_PROPOSED,
        self::STAGE_AGREEMENT_ACCEPTED,
        self::STAGE_PUBLISHED_TO_MARKETPLACE,
        self::STAGE_PROFILE_SUGGESTED,
        self::STAGE_VIEWED,
        self::STAGE_INTERESTED,
        self::STAGE_MEETING_SCHEDULED,
        self::STAGE_MEETING_COMPLETED,
        self::STAGE_MEETING_CONFIRMED,
        self::STAGE_MARRIAGE_SETTLED,
        self::STAGE_ENGAGEMENT,
        self::STAGE_MARRIAGE,
        self::STAGE_SHARE_SETTLED,
    ];

    /**
     * Claimed, then confirmed (decision D26). Either Suchak may raise the claim; the customer confirms.
     * The 7-day silent-then-dispute timer is Phase 3 and is deliberately not modelled here.
     *
     * @var list<string>
     */
    public const CONFIRMABLE_STAGES = [
        self::STAGE_MARRIAGE_SETTLED,
        self::STAGE_ENGAGEMENT,
        self::STAGE_MARRIAGE,
    ];

    /**
     * Actor vocabulary is the Suchak domain's existing one — bound, not re-declared.
     *
     * @var list<string>
     */
    public const CLAIM_ACTOR_TYPES = [
        SuchakActivityLog::ACTOR_SUCHAK,
        SuchakActivityLog::ACTOR_ADMIN,
        SuchakActivityLog::ACTOR_SYSTEM,
    ];

    /** @var list<string> */
    public const CONFIRM_ACTOR_TYPES = [
        SuchakActivityLog::ACTOR_USER,
        SuchakActivityLog::ACTOR_ADMIN,
    ];

    protected $table = 'suchak_collaboration_stage_events';

    protected $fillable = [
        'collaboration_request_id',
        'stage_key',
        'claimed_by_actor_type',
        'claimed_by_suchak_account_id',
        'claimed_by_user_id',
        'claimed_at',
        'confirmed_by_actor_type',
        'confirmed_by_user_id',
        'confirmed_at',
        'event_note',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public static function isValidStage(?string $stageKey): bool
    {
        return $stageKey !== null && in_array($stageKey, self::STAGE_LADDER, true);
    }

    public static function requiresConfirmation(string $stageKey): bool
    {
        return in_array($stageKey, self::CONFIRMABLE_STAGES, true);
    }

    /**
     * Position on the ladder. Throws on an unknown key so free text can never leak in.
     */
    public static function stageIndex(string $stageKey): int
    {
        $index = array_search($stageKey, self::STAGE_LADDER, true);
        if ($index === false) {
            throw new InvalidArgumentException('Unknown marketplace stage key: '.$stageKey.'.');
        }

        return (int) $index;
    }

    /**
     * True when $stageKey sits later on the ladder than $comparedTo (null = nothing reached yet).
     */
    public static function isStageAfter(string $stageKey, ?string $comparedTo): bool
    {
        if ($comparedTo === null || ! self::isValidStage($comparedTo)) {
            return true;
        }

        return self::stageIndex($stageKey) > self::stageIndex($comparedTo);
    }

    public function isSettled(): bool
    {
        if (! self::requiresConfirmation((string) $this->stage_key)) {
            return $this->claimed_at !== null;
        }

        return $this->confirmed_at !== null;
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function claimedBySuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'claimed_by_suchak_account_id');
    }

    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak collaboration stage events cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak collaboration stage events cannot be deleted.');
    }
}
