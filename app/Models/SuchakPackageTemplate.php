<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPackageTemplate extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_ARCHIVED,
    ];

    protected $table = 'suchak_package_templates';

    protected $fillable = [
        'template_name',
        'template_name_mr',
        'template_description',
        'template_description_mr',
        'base_price_amount',
        'currency',
        'template_status',
        'created_by_admin_user_id',
        'approved_by_admin_user_id',
        'approved_at',
        'archived_at',
    ];

    protected $casts = [
        'base_price_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(SuchakPackageTemplateStage::class, 'package_template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(SuchakPackageTemplateDeliverable::class, 'package_template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(SuchakServicePackage::class, 'source_template_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_user_id');
    }

    public function isApproved(): bool
    {
        return $this->template_status === self::STATUS_APPROVED;
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak package templates cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak package templates cannot be deleted.');
    }
}
