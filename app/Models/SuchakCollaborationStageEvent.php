<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * The marketplace stage ladder (blueprint section 6a).
 *
 * One row per (owner, ladder stage), where the owner is exactly one of two things:
 *
 *  - `collaboration_request_id` — the ENGAGEMENT. SuchakCollaborationRequest +
 *    SuchakCommissionAgreement (blueprint 6.1) are the engagement; there is no separate
 *    engagements table. Owns every stage from FIRST_ENGAGEMENT_STAGE onward.
 *  - `customer_agreement_id` — the CUSTOMER AGREEMENT REVISION. Owns the four pre-engagement
 *    stages, which happen before any counterparty exists (`published_to_marketplace` is literally
 *    the invitation, so no engagement can exist yet).
 *
 * STAGE_LADDER is the single ordered vocabulary. Installment triggers must be chosen from it and
 * never written as free text. Every other split in this class — which owner a stage belongs to,
 * and which stages need an accepted engagement — is a POSITION on that one ladder, derived by
 * index. There is deliberately no second hand-written list of stages anywhere: a parallel list is
 * free to drift out of order, and out of order the words "before" and "after" stop meaning
 * anything.
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
     * The first stage an ENGAGEMENT can own. Everything strictly before it on the ladder is a
     * pre-engagement fact hanging off the customer agreement revision, because at that point no
     * counterparty has been invited yet — `published_to_marketplace` IS the invitation.
     *
     * A position, not a list: move this constant and both sets move together and stay in order.
     */
    public const FIRST_ENGAGEMENT_STAGE = self::STAGE_PROFILE_SUGGESTED;

    /**
     * The first stage that may only be recorded once the CUSTOMER AGREEMENT is actually satisfied.
     *
     * A position, exactly like the two constants around it, and the position is the whole rule:
     * `agreement_accepted` asserts the customer accepted, and `published_to_marketplace` declares a
     * share OF those accepted terms (D4) — so both, and everything after them, are false unless the
     * agreement itself says so. `registration` and `agreement_proposed` sit BEFORE this line and
     * therefore require nothing beyond an agreement row that belongs to the claiming Suchak: they
     * record acts the Suchak performed (he registered the customer; he put terms in front of them),
     * and the agreement revision IS the artifact those acts produce. Gating them on a status would
     * make the ladder's first rungs depend on a later one.
     *
     * The state itself is read through SuchakCustomerAgreement::isTermsSatisfied() — the existing
     * owner of "are these terms in force". The status list is deliberately NOT restated here.
     */
    public const FIRST_STAGE_REQUIRING_SATISFIED_TERMS = self::STAGE_AGREEMENT_ACCEPTED;

    /**
     * The first stage that requires the engagement to have been ACCEPTED.
     *
     * Section 6a runs `profile_suggested` -> `viewed` -> `interested` BEFORE acceptance: a
     * marketplace proposal is created `pending` (the helper names their candidate), the customer
     * then opens it — and D11 attaches the 12-month anti-circumvention clause at `viewed`. Gating
     * every stage on acceptance made the clause's own trigger unrecordable at the moment it is
     * supposed to bind.
     *
     * The line falls at `meeting_scheduled` because that is the first stage that needs the two
     * Suchaks to actually be working together: arranging a meeting needs contact exchange, and
     * `canExchangeContact()` already requires an accepted engagement with both commission
     * acknowledgements. Everything before it is one side recording what happened on their own side.
     */
    public const FIRST_STAGE_REQUIRING_ACCEPTED_ENGAGEMENT = self::STAGE_MEETING_SCHEDULED;

    /**
     * WHO may write each rung (blueprint 6a names an actor per stage; A7 makes one of them a money
     * rule). Four values, and each of them is a role the engagement can already name — no new
     * column, no second copy of an account id:
     *
     *  - CUSTOMER_OWNER  the Suchak who holds the customer, the agreement and the collection
     *                    (SuchakCollaborationRequest::customerOwnerSuchakAccountId()).
     *  - HELPER          the other side (…::helpingSuchakAccountId()).
     *  - EITHER_SUCHAK   D26: either Suchak may raise the claim; the customer then confirms.
     *  - CUSTOMER        the family itself. There is no customer door yet (D23 defers OTP, §10 S4),
     *                    so these rungs are refused to everyone rather than handed to a Suchak.
     *                    An honest gap beats a forged record.
     */
    public const CLAIMANT_CUSTOMER_OWNER = 'customer_owner_suchak';
    public const CLAIMANT_HELPER = 'helping_suchak';
    public const CLAIMANT_EITHER_SUCHAK = 'either_suchak';
    public const CLAIMANT_CUSTOMER = 'customer';

    /**
     * The claimant of every rung, in ladder order — the ACTOR half of section 6a's ladder.
     *
     * Why a keyed map and not another position constant: the actor sequence is not monotone
     * (owner, owner, owner, owner, helper, customer, customer, either, helper, customer, either,
     * either, either, helper), so no boundary index can express it, the way
     * FIRST_ENGAGEMENT_STAGE and FIRST_STAGE_REQUIRING_ACCEPTED_ENGAGEMENT do for their splits.
     * PHP cannot compute `array_keys()` in a constant expression, so this cannot literally BE
     * STAGE_LADDER either. It is therefore kept from drifting by two things instead of by wishing:
     * {@see claimantFor()} FAILS CLOSED — a rung added to the ladder without an entry here is
     * unclaimable by anybody until someone names its actor — and a test pins
     * `array_keys(self::STAGE_CLAIMANTS) === self::STAGE_LADDER`, order included. The same
     * reasoning STAGE_LABELS_MR already carries: the vocabulary and what it means live together.
     *
     * The blueprint's own words, rung by rung:
     *  - registration / agreement_proposed / agreement_accepted / published_to_marketplace — the
     *    four pre-engagement rungs hang off the customer agreement, whose owner is by construction
     *    the customer-owning Suchak. `agreement_accepted` asserts a CUSTOMER act, and the Suchak
     *    may only transcribe it because the agreement row independently carries that act
     *    (terms_status); see FIRST_STAGE_REQUIRING_SATISFIED_TERMS. That is the difference between
     *    this rung and `viewed`: one has an independent record of the customer's act, the other
     *    has nothing but the Suchak's word.
     *  - profile_suggested — "helper names their candidate".
     *  - viewed — "customer opens it", and D11's 12-month clause attaches HERE.
     *  - interested — the blueprint names no actor. Decided: the customer. पसंती दर्शवली is a
     *    statement about the family's own mind, sitting between two customer rungs, and nothing
     *    outside the Suchak's word could contradict it.
     *  - meeting_scheduled — the blueprint names no actor. Decided: either Suchak. Arranging is
     *    joint work, and this rung is already gated on an ACCEPTED engagement, which needs both
     *    commission acknowledgements before contact can be exchanged at all.
     *  - meeting_completed — "helper marks".
     *  - meeting_confirmed — "customer".
     *  - marriage_settled / engagement / marriage — D26, either Suchak claims, customer confirms.
     *  - share_settled — "helper marks; closes the loop". A7 makes this a money rule: the declarer
     *    marking his own share settled would forge the realized-vs-declared ratio that is the only
     *    thing stopping an inflated declaration. The payee marks the receipt, never the payer.
     *
     * @var array<string, string>
     */
    public const STAGE_CLAIMANTS = [
        self::STAGE_REGISTRATION => self::CLAIMANT_CUSTOMER_OWNER,
        self::STAGE_AGREEMENT_PROPOSED => self::CLAIMANT_CUSTOMER_OWNER,
        self::STAGE_AGREEMENT_ACCEPTED => self::CLAIMANT_CUSTOMER_OWNER,
        self::STAGE_PUBLISHED_TO_MARKETPLACE => self::CLAIMANT_CUSTOMER_OWNER,
        self::STAGE_PROFILE_SUGGESTED => self::CLAIMANT_HELPER,
        self::STAGE_VIEWED => self::CLAIMANT_CUSTOMER,
        self::STAGE_INTERESTED => self::CLAIMANT_CUSTOMER,
        self::STAGE_MEETING_SCHEDULED => self::CLAIMANT_EITHER_SUCHAK,
        self::STAGE_MEETING_COMPLETED => self::CLAIMANT_HELPER,
        self::STAGE_MEETING_CONFIRMED => self::CLAIMANT_CUSTOMER,
        self::STAGE_MARRIAGE_SETTLED => self::CLAIMANT_EITHER_SUCHAK,
        self::STAGE_ENGAGEMENT => self::CLAIMANT_EITHER_SUCHAK,
        self::STAGE_MARRIAGE => self::CLAIMANT_EITHER_SUCHAK,
        self::STAGE_SHARE_SETTLED => self::CLAIMANT_HELPER,
    ];

    /** The engagement owner column — one row per (engagement, stage). */
    public const OWNER_COLUMN_COLLABORATION_REQUEST = 'collaboration_request_id';

    /** The pre-engagement owner column — one row per (customer agreement revision, stage). */
    public const OWNER_COLUMN_CUSTOMER_AGREEMENT = 'customer_agreement_id';

    /**
     * Every column that may own a stage event. Exactly one is set on any row; `assertOwnership()`
     * enforces that on every save.
     *
     * The challenge object (blueprint 11 phase 2) becomes a third owner by adding its column and
     * foreign key in its own migration and one entry here — the guard, the writers and the unique
     * indexes all extend unchanged.
     *
     * @var list<string>
     */
    public const OWNER_COLUMNS = [
        self::OWNER_COLUMN_COLLABORATION_REQUEST,
        self::OWNER_COLUMN_CUSTOMER_AGREEMENT,
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
        'customer_agreement_id',
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

    /**
     * The exactly-one-owner rule. MySQL and SQLite cannot both be given the same CHECK through
     * Laravel's schema builder, so the invariant lives here — on `saving`, which every writer
     * passes through — instead of being written twice in two dialects.
     */
    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            $event->assertOwnership();
        });
    }

    public static function isValidStage(?string $stageKey): bool
    {
        return $stageKey !== null && in_array($stageKey, self::STAGE_LADDER, true);
    }

    /**
     * True for the four stages that happen before any counterparty exists.
     */
    public static function isPreEngagementStage(string $stageKey): bool
    {
        return self::stageIndex($stageKey) < self::stageIndex(self::FIRST_ENGAGEMENT_STAGE);
    }

    /**
     * The stages owned by the customer agreement revision, in ladder order.
     *
     * @return list<string>
     */
    public static function preEngagementStages(): array
    {
        return array_values(array_filter(
            self::STAGE_LADDER,
            static fn (string $stageKey): bool => self::isPreEngagementStage($stageKey),
        ));
    }

    /**
     * The stages owned by an engagement, in ladder order.
     *
     * @return list<string>
     */
    public static function engagementStages(): array
    {
        return array_values(array_filter(
            self::STAGE_LADDER,
            static fn (string $stageKey): bool => ! self::isPreEngagementStage($stageKey),
        ));
    }

    /**
     * True when the engagement must already be accepted for this stage to be claimed. False for
     * `profile_suggested` / `viewed` / `interested`, which section 6a places before acceptance —
     * and `viewed` is where D11's 12-month clause binds, so it must be claimable on a pending
     * engagement or the clause has no trigger.
     */
    public static function requiresAcceptedEngagement(string $stageKey): bool
    {
        return self::stageIndex($stageKey) >= self::stageIndex(self::FIRST_STAGE_REQUIRING_ACCEPTED_ENGAGEMENT);
    }

    /**
     * True when the customer agreement must already be satisfied for this stage to be recorded.
     * A position on the one ladder, exactly like requiresAcceptedEngagement().
     */
    public static function requiresSatisfiedCustomerTerms(string $stageKey): bool
    {
        return self::stageIndex($stageKey) >= self::stageIndex(self::FIRST_STAGE_REQUIRING_SATISFIED_TERMS);
    }

    /**
     * Who may write this rung. FAILS CLOSED: a stage on the ladder with no entry in STAGE_CLAIMANTS
     * is claimable by nobody, so adding a rung without naming its actor cannot silently produce a
     * rung anyone may forge. `stageIndex()` runs first so free text still reports as free text.
     */
    public static function claimantFor(string $stageKey): string
    {
        self::stageIndex($stageKey);

        $claimant = self::STAGE_CLAIMANTS[$stageKey] ?? null;
        if ($claimant === null) {
            throw new InvalidArgumentException(
                'Marketplace stage "'.$stageKey.'" names no claimable actor; it cannot be recorded by anyone.'
            );
        }

        return $claimant;
    }

    /**
     * True for the rungs the FAMILY alone can know. Nobody can record these today: the customer has
     * no door of their own (D23/S4), and handing them to a Suchak is precisely the forgery A7 and
     * §7.2 exist to prevent.
     */
    public static function isCustomerClaimedStage(string $stageKey): bool
    {
        return self::claimantFor($stageKey) === self::CLAIMANT_CUSTOMER;
    }

    /**
     * The one owner column a given stage is allowed to hang off. Throws on an unknown key.
     */
    public static function ownerColumnFor(string $stageKey): string
    {
        return self::isPreEngagementStage($stageKey)
            ? self::OWNER_COLUMN_CUSTOMER_AGREEMENT
            : self::OWNER_COLUMN_COLLABORATION_REQUEST;
    }

    /**
     * The owner column actually set on this row, or null when it belongs to nothing.
     */
    public function ownerColumn(): ?string
    {
        foreach (self::OWNER_COLUMNS as $column) {
            if ($this->{$column} !== null) {
                return $column;
            }
        }

        return null;
    }

    /**
     * A stage event must belong to exactly one owner, and to the owner its stage says it belongs
     * to. Both halves matter: nothing may float free, and nothing may be filed under the wrong
     * object — a `published_to_marketplace` row hanging off an engagement would claim that a
     * counterparty existed before the invitation was sent.
     */
    public function assertOwnership(): void
    {
        $stageKey = (string) $this->stage_key;
        if (! self::isValidStage($stageKey)) {
            throw new InvalidArgumentException('Unknown marketplace stage key: '.$stageKey.'.');
        }

        $set = array_values(array_filter(
            self::OWNER_COLUMNS,
            fn (string $column): bool => $this->{$column} !== null,
        ));

        if ($set === []) {
            throw new InvalidArgumentException('A marketplace stage event must name an owner; this one names none.');
        }

        if (count($set) > 1) {
            throw new InvalidArgumentException(
                'A marketplace stage event must name exactly one owner; this one names '.implode(' and ', $set).'.'
            );
        }

        $expected = self::ownerColumnFor($stageKey);
        if ($set[0] !== $expected) {
            throw new InvalidArgumentException(
                'Marketplace stage "'.$stageKey.'" belongs to '.$expected.', not to '.$set[0].'.'
            );
        }
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

    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
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
