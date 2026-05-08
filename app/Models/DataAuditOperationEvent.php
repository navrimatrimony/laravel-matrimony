<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataAuditOperationEvent extends Model
{
    protected $fillable = [
        'operation',
        'status',
        'duration_ms',
        'memory_peak_kb',
        'error_message',
        'context',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'memory_peak_kb' => 'integer',
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}

