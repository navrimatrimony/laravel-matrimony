<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakTrainingModule extends Model
{
    use HasFactory;

    public const CATEGORY_PLATFORM_SAFETY = 'platform_safety';
    public const CATEGORY_PAYMENT = 'payment';
    public const CATEGORY_DISPUTE = 'dispute';
    public const CATEGORY_PRIVACY = 'privacy';
    public const CATEGORY_CUSTOMER_COMMUNICATION = 'customer_communication';

    public const CATEGORIES = [
        self::CATEGORY_PLATFORM_SAFETY,
        self::CATEGORY_PAYMENT,
        self::CATEGORY_DISPUTE,
        self::CATEGORY_PRIVACY,
        self::CATEGORY_CUSTOMER_COMMUNICATION,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    protected $table = 'suchak_training_modules';

    protected $fillable = [
        'module_key',
        'module_title',
        'module_category',
        'module_status',
        'is_required_for_certificate',
        'sort_order',
        'summary',
        'content_outline',
        'created_by_admin_user_id',
        'admin_audit_log_id',
    ];

    protected $casts = [
        'is_required_for_certificate' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function completions(): HasMany
    {
        return $this->hasMany(SuchakTrainingCompletion::class, 'training_module_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak training modules cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak training modules cannot be deleted.');
    }
}
