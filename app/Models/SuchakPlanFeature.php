<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class SuchakPlanFeature extends Model
{
    use HasFactory;

    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_STRING = 'string';

    public const FEATURE_ACTIVE_PROFILE_LIMIT = 'active_profile_limit';
    public const FEATURE_MONTHLY_UPLOAD_LIMIT = 'monthly_upload_limit';
    public const FEATURE_LEAD_REQUEST_LIMIT = 'lead_request_limit';
    public const FEATURE_COLLABORATION_REQUEST_LIMIT = 'collaboration_request_limit';
    public const FEATURE_PDF_DOWNLOAD_SHARE_LIMIT = 'pdf_download_share_limit';
    public const FEATURE_LEDGER_FEATURES = 'ledger_features';
    public const FEATURE_CRM_FEATURES = 'crm_features';
    public const FEATURE_PRIORITY_SUPPORT = 'priority_support';
    public const FEATURE_BULK_UPLOAD_ACCESS = 'bulk_upload_access';

    public const FEATURE_KEYS = [
        self::FEATURE_ACTIVE_PROFILE_LIMIT,
        self::FEATURE_MONTHLY_UPLOAD_LIMIT,
        self::FEATURE_LEAD_REQUEST_LIMIT,
        self::FEATURE_COLLABORATION_REQUEST_LIMIT,
        self::FEATURE_PDF_DOWNLOAD_SHARE_LIMIT,
        self::FEATURE_LEDGER_FEATURES,
        self::FEATURE_CRM_FEATURES,
        self::FEATURE_PRIORITY_SUPPORT,
        self::FEATURE_BULK_UPLOAD_ACCESS,
    ];

    protected $table = 'suchak_plan_features';

    protected $fillable = [
        'suchak_plan_id',
        'feature_key',
        'value_type',
        'feature_value',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function suchakPlan(): BelongsTo
    {
        return $this->belongsTo(SuchakPlan::class);
    }

    public function typedValue(): int|bool|string|null
    {
        if (! $this->is_enabled) {
            return null;
        }

        $raw = trim((string) ($this->feature_value ?? ''));

        return match ($this->value_type) {
            self::TYPE_INTEGER => $this->integerValue($raw),
            self::TYPE_BOOLEAN => $this->booleanValue($raw),
            self::TYPE_STRING => $raw,
            default => throw new InvalidArgumentException('Invalid Suchak plan feature value type.'),
        };
    }

    private function integerValue(string $raw): int
    {
        if ($raw === '' || ! preg_match('/^-?\d+$/', $raw)) {
            throw new InvalidArgumentException('Invalid integer Suchak plan feature value.');
        }

        return (int) $raw;
    }

    private function booleanValue(string $raw): bool
    {
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}
