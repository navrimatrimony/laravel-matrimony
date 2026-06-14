<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuchakVerificationRecord extends Model
{
    use HasFactory;

    public const TYPE_IDENTITY = 'identity';
    public const TYPE_OFFICE = 'office';
    public const TYPE_BUSINESS = 'business';
    public const TYPE_PHONE = 'phone';
    public const TYPE_OTHER = 'other';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'suchak_verification_records';

    protected $fillable = [
        'suchak_account_id',
        'verification_type',
        'document_path',
        'admin_status',
        'admin_user_id',
        'remarks',
        'remarks_mr',
        'verified_at',
        'rejected_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
