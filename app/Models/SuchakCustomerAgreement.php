<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCustomerAgreement extends Model
{
    use HasFactory;

    public const TERMS_NOT_REQUIRED = 'not_required';
    public const TERMS_PENDING = 'pending';
    public const TERMS_ACCEPTED = 'accepted';
    public const TERMS_DECLINED = 'declined';
    public const TERMS_BYPASSED = 'bypassed';
    public const TERMS_EXPIRED = 'expired';
    public const TERMS_SUPERSEDED = 'superseded';

    public const TERMS_STATUSES = [
        self::TERMS_NOT_REQUIRED,
        self::TERMS_PENDING,
        self::TERMS_ACCEPTED,
        self::TERMS_DECLINED,
        self::TERMS_BYPASSED,
        self::TERMS_EXPIRED,
        self::TERMS_SUPERSEDED,
    ];

    public const POLICY_STRICT = 'strict';
    public const POLICY_RECOMMENDED = 'recommended';
    public const POLICY_OPTIONAL = 'optional';

    public const POLICY_MODES = [
        self::POLICY_STRICT,
        self::POLICY_RECOMMENDED,
        self::POLICY_OPTIONAL,
    ];

    protected $table = 'suchak_customer_agreements';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'service_package_id',
        'supersedes_agreement_id',
        'agreement_revision',
        'terms_status',
        'terms_policy_mode',
        'agreement_snapshot_hash',
        'package_name',
        'package_description',
        'price_amount',
        'currency',
        'agreement_title',
        'agreement_body',
        'invoice_note',
        'created_by_user_id',
        'accepted_by_user_id',
        'accepted_at',
        'declined_by_user_id',
        'declined_at',
        'decline_reason',
        'bypassed_by_user_id',
        'bypassed_at',
        'bypass_reason',
        'expired_at',
        'superseded_at',
    ];

    protected $casts = [
        'agreement_revision' => 'integer',
        'price_amount' => 'decimal:2',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'bypassed_at' => 'datetime',
        'expired_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackage::class, 'service_package_id');
    }

    public function supersedesAgreement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_agreement_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function bypassedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bypassed_by_user_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(SuchakCustomerAgreementStage::class, 'customer_agreement_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(SuchakCustomerAgreementDeliverable::class, 'customer_agreement_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(SuchakPaymentRequest::class, 'customer_agreement_id');
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(SuchakCustomerPayment::class, 'customer_agreement_id');
    }

    public function isTermsSatisfied(): bool
    {
        return in_array($this->terms_status, [
            self::TERMS_NOT_REQUIRED,
            self::TERMS_ACCEPTED,
            self::TERMS_BYPASSED,
        ], true);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer agreements cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer agreements cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->assertMutable();

        return parent::update($attributes, $options);
    }

    public function save(array $options = []): bool
    {
        $this->assertMutable();

        return parent::save($options);
    }

    private function assertMutable(): void
    {
        if (! $this->exists) {
            return;
        }

        if (in_array($this->getOriginal('terms_status'), [
            self::TERMS_ACCEPTED,
            self::TERMS_BYPASSED,
            self::TERMS_NOT_REQUIRED,
        ], true)) {
            throw new RuntimeException('Suchak customer agreements are immutable after acceptance, bypass, or not-required finalization.');
        }
    }
}
