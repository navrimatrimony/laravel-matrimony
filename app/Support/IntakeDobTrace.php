<?php

namespace App\Support;

/**
 * Temporary opt-in tracing for date_of_birth through parse → save → preview.
 * Set DOB_TRACE_INTAKE_IDS=comma-separated ids (e.g. "4,12") in .env.
 */
final class IntakeDobTrace
{
    public static function enabled(?int $intakeId): bool
    {
        if ($intakeId === null || $intakeId <= 0) {
            return false;
        }
        $ids = config('intake.dob_trace_intake_ids', []);

        return is_array($ids) && in_array($intakeId, $ids, true);
    }
}
