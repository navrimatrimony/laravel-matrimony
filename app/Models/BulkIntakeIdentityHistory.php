<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkIntakeIdentityHistory extends Model
{
    protected $table = 'bulk_intake_identity_histories';

    public const REASON_ALREADY_MARRIED = 'already_married';

    public const REASON_NOT_INTERESTED = 'not_interested';

    public const REASON_WRONG_NUMBER = 'wrong_number';

    public const REASON_DO_NOT_SUGGEST = 'do_not_suggest';

    public const REASON_NO_RESPONSE = 'no_response';

    public const SOURCE_ADMIN_SCREENING = 'admin_screening';

    public const SOURCE_ADMIN_DUPLICATE = 'admin_duplicate';

    public const SOURCE_WHATSAPP_REPLY = 'whatsapp_reply';

    /**
     * @var list<string>
     */
    public const BLOCKING_REASON_CODES = [
        self::REASON_ALREADY_MARRIED,
        self::REASON_NOT_INTERESTED,
        self::REASON_WRONG_NUMBER,
        self::REASON_DO_NOT_SUGGEST,
        self::REASON_NO_RESPONSE,
    ];

    protected $fillable = [
        'reason_code',
        'normalized_mobile',
        'normalized_name',
        'normalized_dob',
        'normalized_gender',
        'source_type',
        'source_bulk_intake_batch_item_id',
        'source_biodata_intake_id',
        'recorded_by_user_id',
        'note',
    ];

    public function sourceBatchItem(): BelongsTo
    {
        return $this->belongsTo(BulkIntakeBatchItem::class, 'source_bulk_intake_batch_item_id');
    }

    public function sourceBiodataIntake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class, 'source_biodata_intake_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
