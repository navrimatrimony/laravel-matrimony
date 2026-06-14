<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakTrainingCompletion extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_REVOKED,
    ];

    protected $table = 'suchak_training_completions';

    protected $fillable = [
        'suchak_account_id',
        'training_module_id',
        'completion_status',
        'score_percent',
        'completion_note',
        'completion_note_mr',
        'completed_by_admin_user_id',
        'admin_audit_log_id',
        'completed_at',
    ];

    protected $casts = [
        'score_percent' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function trainingModule(): BelongsTo
    {
        return $this->belongsTo(SuchakTrainingModule::class, 'training_module_id');
    }

    public function completedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak training completion records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak training completion records cannot be deleted.');
    }
}
