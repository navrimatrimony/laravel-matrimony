<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second and final pass of 2026_07_27_160000_stop_database_from_authoring_chat_timestamps.
 *
 * Same root cause: these tables were created on a MySQL server running with
 * `explicit_defaults_for_timestamp = OFF`, where the *first* NOT NULL TIMESTAMP
 * column of a table is silently given `DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP`. No migration ever asked for it — a bare
 * `$table->timestamp('x')` was enough.
 *
 * The damage is not the default, it is the ON UPDATE: *any* UPDATE to the row
 * rewrites the column, with CURRENT_TIMESTAMP evaluated in the MySQL session
 * zone (SYSTEM = UTC) rather than the app zone (Asia/Kolkata) — so the rewritten
 * value is also 5:30 behind everything the application wrote. On `messages.sent_at`
 * this reordered live conversations and wrongly tripped the reply gate.
 *
 * The nine columns below are all application-authored, and one of them is a
 * safety hazard rather than an audit-quality problem:
 *
 *   contact_grants.valid_until  — the consent/contact-grant expiry. A grant row is
 *   updated when it is revoked (`revoked_at`, `revoked_by`). Under the auto
 *   attribute that UPDATE also rewrites `valid_until` to "now", which every
 *   read path treats as "this grant has just expired". Latent only because the
 *   table is still empty in production; it would have fired on the first revoke.
 *
 *   contact_access_log.unlocked_at, profile_field_locks.locked_at,
 *   field_value_history.changed_at, profile_change_history.changed_at,
 *   profile_verification_tag_audits.performed_at, conflict_records.detected_at
 *   — governance / SSOT audit trail. An audit row whose timestamp the database
 *   can silently move is not an audit trail.
 *
 *   showcase_presence_sessions.started_at — ShowcasePresenceService::closeDueSessions()
 *   updates every due row to stamp `ended_at`, which under the auto attribute
 *   dragged `started_at` forward to the close time on every scheduler tick.
 *
 *   addresses.updated_at — benign in effect (the Location model authors it through
 *   Eloquent, and nothing reads it), but it is the only `updated_at` in the whole
 *   schema carrying the attribute. Normalising it keeps the recurrence guard's
 *   allow-list empty, so the guard stays a hard rule with no exceptions to erode.
 *
 * Every insert path was checked and already writes its column explicitly
 * (ContactRequestService, InterestActionService, ShowcasePresenceService,
 * ProfileFieldLockService, FieldValueHistoryService, MutationService,
 * TagAssignmentService, ConflictDetectionService / ConflictPolicy), so dropping
 * the DEFAULT along with the ON UPDATE breaks no writer.
 *
 * This migration changes authorship only. Nullability and column type are
 * preserved exactly as declared in each table's create migration.
 */
return new class extends Migration
{
    /**
     * table => [column, nullable]. `nullable` mirrors the original create
     * migration; it is preserved, not changed.
     *
     * @var array<int, array{0: string, 1: string, 2: bool}>
     */
    private const TARGETS = [
        ['contact_grants', 'valid_until', false],
        ['contact_access_log', 'unlocked_at', false],
        ['showcase_presence_sessions', 'started_at', false],
        ['profile_field_locks', 'locked_at', false],
        ['field_value_history', 'changed_at', false],
        ['profile_change_history', 'changed_at', false],
        ['profile_verification_tag_audits', 'performed_at', false],
        ['conflict_records', 'detected_at', false],
        // Laravel's own timestamps() column: `timestamp null default null`.
        ['addresses', 'updated_at', true],
    ];

    public function up(): void
    {
        if (! $this->isMySqlLike()) {
            // Only MySQL/MariaDB have the implicit TIMESTAMP auto-column behaviour.
            return;
        }

        foreach (self::TARGETS as [$table, $column, $nullable]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (! $this->isDatabaseGenerated($table, $column)) {
                // Already clean (fresh install, or this migration re-run). Idempotent.
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` TIMESTAMP %s',
                $table,
                $column,
                $nullable ? 'NULL DEFAULT NULL' : 'NOT NULL'
            ));

            $this->assertNoLongerDatabaseGenerated($table, $column);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible. Restoring DEFAULT/ON UPDATE CURRENT_TIMESTAMP
        // would re-introduce silent corruption of application-authored timestamps.
    }

    private function isMySqlLike(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function isDatabaseGenerated(string $table, string $column): bool
    {
        $row = $this->describe($table, $column);

        if (! $row) {
            return false;
        }

        return str_contains(strtolower((string) ($row->EXTRA ?? '')), 'on update')
            || str_contains(strtoupper((string) ($row->COLUMN_DEFAULT ?? '')), 'CURRENT_TIMESTAMP');
    }

    /**
     * A server running with `explicit_defaults_for_timestamp = OFF` would re-apply the
     * auto attributes to the plain TIMESTAMP definition above. Fail loudly rather than
     * let the corruption return unnoticed.
     */
    private function assertNoLongerDatabaseGenerated(string $table, string $column): void
    {
        if (! $this->isDatabaseGenerated($table, $column)) {
            return;
        }

        $row = $this->describe($table, $column);

        throw new RuntimeException(sprintf(
            '%s.%s is still database-generated (default="%s", extra="%s"). '
            .'This server runs with explicit_defaults_for_timestamp = OFF; set it to ON '
            .'(my.cnf) and re-run this migration.',
            $table,
            $column,
            (string) ($row->COLUMN_DEFAULT ?? ''),
            (string) ($row->EXTRA ?? '')
        ));
    }

    private function describe(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }
};
