<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataEngineAdminAction extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'recipe',
        'status',
        'workflow_state',
        'progress_percent',
        'dry_run',
        'is_destructive',
        'rollback_available',
        'approved_by',
        'approved_at',
        'eta_at',
        'request_payload',
        'before_payload',
        'after_payload',
        'validation_payload',
        'rollback_payload',
        'result_payload',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'is_destructive' => 'boolean',
            'rollback_available' => 'boolean',
            'progress_percent' => 'integer',
            'approved_at' => 'datetime',
            'eta_at' => 'datetime',
            'request_payload' => 'array',
            'before_payload' => 'array',
            'after_payload' => 'array',
            'validation_payload' => 'array',
            'rollback_payload' => 'array',
            'result_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

