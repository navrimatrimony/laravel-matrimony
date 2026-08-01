<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * The challenge object (blueprint D4, D18, section 11 phase 2).
 *
 * A Suchak publishes: *"I hold this customer; I will pay X to whoever brings the match."* It is
 * open BEFORE any helper exists, which is the whole reason it needed a table of its own — every
 * candidate owner in the schema names two Suchak accounts NOT NULL from row one.
 *
 * The invitation, never the engagement. When a helper proposes and the publisher accepts, the
 * existing SuchakCollaborationRequest + SuchakCommissionAgreement pair IS the engagement
 * (section 6.1). This row points at that; it does not become it.
 *
 * The candidate's visible facts are not here and must never be copied here. There is exactly one
 * owner of cross-Suchak presentation — SuchakCandidateMaskingService::maskedSummary, with the four
 * defaults of D19a (name, village, detailed address, mobile hidden unless the originating Suchak
 * reveals them) and the photograph always shown.
 */
class SuchakMarketplaceChallenge extends Model
{
    use HasFactory;

    /** Live and browsable. The only state a helper can act on. */
    public const STATUS_OPEN = 'open';

    /** The publisher pulled it. A8 treats this as an attack surface, hence `withdrawn_reason`. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * A match was found through it and the search is over.
     *
     * NO WRITER IN THIS SLICE, and that is deliberate rather than forgotten: the only honest moment
     * to write it is when a proposal made against this challenge is accepted, which is
     * accept-by-proposing — the next slice. It is declared here because the lifecycle vocabulary is
     * what that slice binds to, and a status invented later under a different name is how two
     * screens end up disagreeing about what "done" means.
     */
    public const STATUS_FULFILLED = 'fulfilled';

    /** Past the expiry the publisher chose. Written by expireDue(). */
    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_WITHDRAWN,
        self::STATUS_FULFILLED,
        self::STATUS_EXPIRED,
    ];

    /**
     * D18 — the marketplace is visible to VERIFIED Suchaks only; A10 ties marketplace participation
     * to the verification badge because one person running two accounts and colluding is otherwise
     * free money.
     *
     * One value today. It is a column and not a hardcoded `where` for the reason section 9 gives
     * about the commission split: a rule that exists only because one query happens to filter on it
     * is not a rule, and the next serializer will not know it was supposed to.
     */
    public const AUDIENCE_VERIFIED_SUCHAKS = 'verified_suchaks';

    /** @var list<string> */
    public const AUDIENCES = [
        self::AUDIENCE_VERIFIED_SUCHAKS,
    ];

    /**
     * The share vocabulary is SuchakCommissionAgreement's, bound rather than re-declared — the
     * commission agreement written when a helper is accepted must be able to carry the same words
     * the challenge declared, or the declaration and the engagement would speak two languages.
     *
     * Only two of its four values can describe a one-directional declaration:
     *  - SPLIT_TO_BE_DISCUSSED is forbidden by D4 (decided upfront, not negotiable by the helper) —
     *    "to be discussed" is the one thing a challenge can never say.
     *  - SPLIT_EQUAL_PERCENT is SPLIT_CUSTOM_PERCENT at 50.00, and a second spelling of one number
     *    is how two screens end up disagreeing.
     *
     * @var list<string>
     */
    public const DECLARED_SHARE_TYPES = [
        SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
        SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT,
    ];

    protected $table = 'suchak_marketplace_challenges';

    protected $fillable = [
        'suchak_account_id',
        'representation_id',
        'customer_agreement_id',
        'declared_share_type',
        'declared_share_percent',
        'declared_share_amount',
        // No `share_currency`. See declaredShareCurrency() — the currency is READ from the
        // agreement this row already points at, and there is no column to fill.
        'audience',
        'status',
        'publisher_note',
        'published_by_user_id',
        'published_at',
        'expires_at',
        'withdrawn_by_user_id',
        'withdrawn_at',
        'withdrawn_reason',
        'fulfilled_at',
    ];

    protected $casts = [
        'declared_share_percent' => 'decimal:2',
        'declared_share_amount' => 'decimal:2',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    /**
     * The declared share must say exactly one thing. A row carrying both a percent and a rupee
     * figure has two answers to "what do I get paid", and D4 says the helper accepts the share as
     * declared with no negotiation afterwards — so there is no later conversation in which the
     * ambiguity could be resolved. Guarded on `saving`, which every writer passes through, for the
     * same reason the stage ladder's exactly-one-owner rule lives there: Laravel's schema builder
     * has no CHECK verb and production is MySQL while the suite is SQLite.
     */
    protected static function booted(): void
    {
        static::saving(function (self $challenge): void {
            $challenge->assertDeclaredShare();
        });
    }

    public function assertDeclaredShare(): void
    {
        $type = (string) $this->declared_share_type;

        if (! in_array($type, self::DECLARED_SHARE_TYPES, true)) {
            throw new InvalidArgumentException('Unknown declared share type: '.$type.'.');
        }

        if (! in_array((string) $this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown challenge status: '.$this->status.'.');
        }

        if (! in_array((string) $this->audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException('Unknown challenge audience: '.$this->audience.'.');
        }

        $hasPercent = $this->declared_share_percent !== null;
        $hasAmount = $this->declared_share_amount !== null;

        if ($hasPercent && $hasAmount) {
            throw new InvalidArgumentException('A declared share is either a percent or a fixed amount, never both.');
        }

        if ($type === SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT) {
            if (! $hasPercent) {
                throw new InvalidArgumentException('A percent share must declare declared_share_percent.');
            }

            $percent = (float) $this->declared_share_percent;
            if ($percent <= 0.0 || $percent > 100.0) {
                throw new InvalidArgumentException('A declared share percent must be above 0 and at most 100.');
            }

            return;
        }

        if (! $hasAmount) {
            throw new InvalidArgumentException('A fixed share must declare declared_share_amount.');
        }

        if ((float) $this->declared_share_amount <= 0.0) {
            throw new InvalidArgumentException('A declared share amount must be above 0.');
        }
    }

    /**
     * The currency of the declared share — READ, never stored.
     *
     * A share is a SLICE of the success fee on the package the customer agreement froze, and
     * `customer_agreement_id` points straight at that agreement. So the share cannot have a currency
     * of its own: one fact, one owner (frozen workspace no-duplicate rule).
     *
     * The owner is `suchak_service_packages.currency`; `suchak_customer_agreements.currency` is its
     * snapshot, copied at proposal time by SuchakAgreementService, covered by
     * `agreement_snapshot_hash`, and immovable by a re-quote (SuchakPackageCatalogService::
     * applyPlanTerms touches "the five money columns and nothing else, so a re-quote can never move
     * a package's name, scope, currency or publish state"). The snapshot is read FIRST because D3
     * freezes what the customer accepted; the package is the source it was copied from, so falling
     * back to it on a NULL snapshot is the same fact and not a second one.
     *
     * This method exists rather than an inline read in the payload builder because accept-by-
     * proposing (the next slice) writes a SuchakCommissionAgreement that needs the identical answer.
     * Two places computing it is how the declaration and the engagement end up in two currencies.
     */
    public function declaredShareCurrency(): string
    {
        $this->loadMissing('customerAgreement.servicePackage');

        $agreement = $this->customerAgreement;
        $currency = strtoupper(trim((string) ($agreement?->currency ?? '')));

        if ($currency === '') {
            $currency = strtoupper(trim((string) ($agreement?->servicePackage?->currency ?? '')));
        }

        // Both columns are nullable and both default to INR everywhere else in this domain
        // (SuchakCustomerPaymentService, SuchakCrmLedgerService, SuchakCollaborationService all end
        // the same chain). Never a caller-supplied value.
        return $currency !== '' ? $currency : 'INR';
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Past the expiry the publisher chose. A NULL expiry is not overdue — it means "open until I
     * withdraw it", which is a real answer, not a missing one.
     */
    public function isPastExpiry(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * D18's gate, read from the row instead of assumed by the query.
     *
     * The publisher's own account is not admitted through here: a Suchak browsing his own listing
     * is not market discovery, and logging him as a viewer would poison the very read log D18 shows
     * the originating Suchak. The service excludes own challenges from browse for that reason.
     */
    public function audienceAdmits(?SuchakAccount $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return match ($this->audience) {
            self::AUDIENCE_VERIFIED_SUCHAKS => $viewer->isVerified(),
            default => false,
        };
    }

    /**
     * Open, not past its expiry, and admitting this viewer. Expiry is evaluated live rather than
     * trusting `status`, so a challenge whose day has passed stops being browsable at that instant
     * and not whenever the sweep next runs.
     */
    public function isBrowsableBy(?SuchakAccount $viewer): bool
    {
        return $this->isOpen()
            && ! $this->isPastExpiry()
            && $this->audienceAdmits($viewer);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Open AND not past its expiry. The SQL half of isBrowsableBy(); the audience half needs the
     * viewer and is applied by the service.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)
            ->where(function (Builder $inner): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'suchak_account_id');
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
    }

    public function publishedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function withdrawnByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_user_id');
    }

    /**
     * A published declaration is evidence. A7's realized-vs-declared ratio and A8's "the share
     * sticks to candidates already suggested under it for the full 12 months" both read rows a
     * publisher would prefer gone. Withdrawal is the supported way out, and it leaves the row.
     */
    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak marketplace challenges cannot be deleted; withdraw them instead.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak marketplace challenges cannot be deleted; withdraw them instead.');
    }
}
