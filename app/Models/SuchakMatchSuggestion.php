<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One APPEND-ONLY row = "the platform showed candidate C to Suchak S for seeker K"
 * plus what the Suchak then decided about it.
 *
 * NOT `ProfileMatch`. `profile_matches` is a replace-on-write cache of the CURRENT
 * top matches (MatchingService::replacePersistedMatches deletes and re-inserts);
 * it has no history and no decision. This model is the history + the decision, and
 * its rows are never deleted or bulk-replaced — only their decision columns change.
 *
 * @property int $suchak_account_id
 * @property int|null $representation_id
 * @property int $seeker_profile_id
 * @property int $candidate_profile_id
 * @property string $run_key
 * @property int|null $score
 * @property array<int|string, mixed>|null $reasons_json
 * @property Carbon $suggested_at
 * @property string $decision
 * @property string|null $rejection_reason_code
 * @property string|null $rejection_note
 * @property Carbon|null $decided_at
 */
class SuchakMatchSuggestion extends Model
{
    use HasFactory;

    /** No decision recorded yet — the suggestion was shown and nothing came back. */
    public const DECISION_PENDING = 'pending';

    /** The Suchak actively picked this candidate for the seeker. */
    public const DECISION_CHOSEN = 'chosen';

    /** The Suchak explicitly rejected it — a reason code should accompany this. */
    public const DECISION_REJECTED = 'rejected';

    /** Shown, seen, and passed over without an explicit rejection. */
    public const DECISION_IGNORED = 'ignored';

    public const DECISIONS = [
        self::DECISION_PENDING,
        self::DECISION_CHOSEN,
        self::DECISION_REJECTED,
        self::DECISION_IGNORED,
    ];

    /** Decisions that count as "the Suchak actually acted on it". */
    public const DECIDED_DECISIONS = [
        self::DECISION_CHOSEN,
        self::DECISION_REJECTED,
        self::DECISION_IGNORED,
    ];

    public const REJECTION_AGE = 'age';
    public const REJECTION_DISTANCE = 'distance';
    public const REJECTION_INCOME = 'income';
    public const REJECTION_KUNDALI = 'kundali';
    public const REJECTION_EDUCATION = 'education';
    public const REJECTION_CASTE = 'caste';
    public const REJECTION_MARITAL_STATUS = 'marital_status';
    public const REJECTION_OTHER = 'other';

    public const REJECTION_REASON_CODES = [
        self::REJECTION_AGE,
        self::REJECTION_DISTANCE,
        self::REJECTION_INCOME,
        self::REJECTION_KUNDALI,
        self::REJECTION_EDUCATION,
        self::REJECTION_CASTE,
        self::REJECTION_MARITAL_STATUS,
        self::REJECTION_OTHER,
    ];

    /**
     * Product decision: once nothing new is left, a previously shown candidate
     * may reappear after roughly a month.
     */
    public const DEFAULT_COOLING_PERIOD_DAYS = 30;

    protected $table = 'suchak_match_suggestions';

    protected $fillable = [
        'suchak_account_id',
        'representation_id',
        'seeker_profile_id',
        'candidate_profile_id',
        'run_key',
        'score',
        'reasons_json',
        'suggested_at',
        'decision',
        'rejection_reason_code',
        'rejection_note',
        'decided_at',
    ];

    protected $casts = [
        'reasons_json' => 'array',
        'score' => 'integer',
        'suggested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function seekerProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'seeker_profile_id');
    }

    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'candidate_profile_id');
    }

    public function scopeForSeeker(Builder $query, MatrimonyProfile|int $seeker): Builder
    {
        return $query->where(
            'seeker_profile_id',
            $seeker instanceof MatrimonyProfile ? $seeker->getKey() : $seeker
        );
    }

    public function scopeForSuchak(Builder $query, SuchakAccount|int $account): Builder
    {
        return $query->where(
            'suchak_account_id',
            $account instanceof SuchakAccount ? $account->getKey() : $account
        );
    }

    /** Suggestions shown on/after the given moment — the cooling-period window. */
    public function scopeSuggestedSince(Builder $query, Carbon $since): Builder
    {
        return $query->where('suggested_at', '>=', $since);
    }

    public function scopeDecided(Builder $query): Builder
    {
        return $query->whereIn('decision', self::DECIDED_DECISIONS);
    }

    public function scopeUndecided(Builder $query): Builder
    {
        return $query->where('decision', self::DECISION_PENDING);
    }

    public function isDecided(): bool
    {
        return in_array($this->decision, self::DECIDED_DECISIONS, true);
    }
}
