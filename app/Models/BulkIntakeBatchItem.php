<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkIntakeBatchItem extends Model
{
    protected $table = 'bulk_intake_batch_items';

    public const INPUT_FILE = 'file';

    public const INPUT_TEXT = 'text';

    public const INPUT_MIXED = 'mixed';

    public const INPUT_UNKNOWN = 'unknown';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_INTAKE_CREATED = 'intake_created';

    public const STATUS_PARSE_QUEUED = 'parse_queued';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';

    public const ALLOWED_INPUT_TYPES = [
        self::INPUT_FILE,
        self::INPUT_TEXT,
        self::INPUT_MIXED,
        self::INPUT_UNKNOWN,
    ];

    protected $fillable = [
        'bulk_intake_batch_id',
        'biodata_intake_id',
        'item_sequence',
        'input_type',
        'original_filename',
        'source_file_path',
        'file_hash',
        'raw_text_hash',
        'idempotency_key',
        'item_status',
        'summary_text',
        'quality_score',
        'failure_code',
        'failure_message',
        'item_meta_json',
    ];

    protected $casts = [
        'item_meta_json' => 'array',
        'quality_score' => 'float',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BulkIntakeBatch::class, 'bulk_intake_batch_id');
    }

    public function biodataIntake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class, 'biodata_intake_id');
    }
}
