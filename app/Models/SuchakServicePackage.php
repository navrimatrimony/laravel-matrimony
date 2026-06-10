<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakServicePackage extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    public const APPROVAL_MODE_ADMIN_REVIEW = 'admin_review';
    public const APPROVAL_MODE_AUTO_PUBLISH = 'auto_publish';

    public const APPROVAL_MODES = [
        self::APPROVAL_MODE_ADMIN_REVIEW,
        self::APPROVAL_MODE_AUTO_PUBLISH,
    ];

    protected $table = 'suchak_service_packages';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'source_template_id',
        'package_name',
        'package_description',
        'price_amount',
        'currency',
        'package_status',
        'approval_policy_mode',
        'requires_admin_approval',
        'customized_by_user_id',
        'submitted_for_review_at',
        'approved_by_admin_user_id',
        'approved_at',
        'rejected_by_admin_user_id',
        'rejected_at',
        'rejection_reason',
        'published_at',
        'archived_at',
    ];

    protected $casts = [
        'price_amount' => 'decimal:2',
        'requires_admin_approval' => 'boolean',
        'submitted_for_review_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(SuchakPackageTemplate::class, 'source_template_id');
    }

    public function customizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customized_by_user_id');
    }

    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_user_id');
    }

    public function rejectedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_admin_user_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(SuchakServicePackageStage::class, 'service_package_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(SuchakServicePackageDeliverable::class, 'service_package_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function customerAgreements(): HasMany
    {
        return $this->hasMany(SuchakCustomerAgreement::class, 'service_package_id')
            ->orderByDesc('agreement_revision')
            ->orderByDesc('id');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(SuchakPaymentRequest::class, 'service_package_id');
    }

    public function isPublished(): bool
    {
        return $this->package_status === self::STATUS_PUBLISHED;
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak service packages cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak service packages cannot be deleted.');
    }
}
