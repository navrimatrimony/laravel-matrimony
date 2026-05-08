<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataEngineRun extends Model
{
    protected $fillable = [
        'mode',
        'status',
        'report_path',
        'error_output',
        'total_issues',
        'total_fixed',
        'quality_score',
        'priority_summary',
        'profile_metrics',
        'conversion_metrics',
        'engine_version',
        'quality_delta',
        'issues_delta',
    ];

    protected function casts(): array
    {
        return [
            'total_issues' => 'integer',
            'total_fixed' => 'integer',
            'quality_score' => 'integer',
            'priority_summary' => 'array',
            'profile_metrics' => 'array',
            'conversion_metrics' => 'array',
            'quality_delta' => 'integer',
            'issues_delta' => 'integer',
        ];
    }

    public function isAnalyze(): bool
    {
        return $this->mode === 'analyze';
    }

    public function isFix(): bool
    {
        return $this->mode === 'fix';
    }
}
