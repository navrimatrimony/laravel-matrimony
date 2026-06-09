<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakActivityLog;
use InvalidArgumentException;

class SuchakActivityLogger
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): SuchakActivityLog
    {
        if (($attributes['actor_type'] ?? null) === SuchakActivityLog::ACTOR_ADMIN
            && empty($attributes['admin_audit_log_id'])) {
            throw new InvalidArgumentException('Admin Suchak activity rows require admin_audit_log_id.');
        }

        $attributes['occurred_at'] ??= now();

        return SuchakActivityLog::query()->create($attributes);
    }
}
