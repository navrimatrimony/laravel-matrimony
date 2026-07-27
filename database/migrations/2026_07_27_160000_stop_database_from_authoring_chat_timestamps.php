<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `messages.sent_at` and `message_policy_cooldowns.locked_until` were declared as
 * `$table->timestamp(...)` — NOT NULL, no explicit default. On a MySQL/MariaDB
 * server running with `explicit_defaults_for_timestamp = OFF` (the legacy default,
 * and the mode this production database was created under), the *first* NOT NULL
 * TIMESTAMP column of a table is silently given
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
 *
 * Production consequence: every ordinary UPDATE of a message row — e.g.
 * ChatMessageService::applyConversationRead() stamping `read_at` when a thread is
 * marked read — made MySQL rewrite `sent_at` to the *read* time, evaluated in the
 * MySQL session time zone (UTC) instead of the app time zone (Asia/Kolkata).
 * Chat ordering and the reply gate both sort on `sent_at DESC`, so a read receipt
 * silently reordered the conversation and made a replied-to thread look like a
 * run of unanswered messages.
 *
 * Both columns are authored exclusively by the application (ChatMessageService,
 * ChatPolicyService). The database must never generate or mutate them.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private const TARGETS = [
        ['messages', 'sent_at'],
        ['message_policy_cooldowns', 'locked_until'],
    ];

    public function up(): void
    {
        if (! $this->isMySqlLike()) {
            // Only MySQL/MariaDB have the implicit TIMESTAMP auto-column behaviour.
            return;
        }

        foreach (self::TARGETS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` TIMESTAMP NOT NULL', $table, $column));

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

    /**
     * A server running with `explicit_defaults_for_timestamp = OFF` would re-apply the
     * auto attributes to the plain `TIMESTAMP NOT NULL` definition above. Fail loudly
     * rather than let the corruption return unnoticed.
     */
    private function assertNoLongerDatabaseGenerated(string $table, string $column): void
    {
        $row = DB::selectOne(
            'SELECT COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if (! $row) {
            return;
        }

        $default = (string) ($row->COLUMN_DEFAULT ?? '');
        $extra = strtolower((string) ($row->EXTRA ?? ''));

        if (str_contains($extra, 'on update') || str_contains(strtoupper($default), 'CURRENT_TIMESTAMP')) {
            throw new RuntimeException(sprintf(
                '%s.%s is still database-generated (default="%s", extra="%s"). '
                .'This server runs with explicit_defaults_for_timestamp = OFF; set it to ON '
                .'(my.cnf) and re-run this migration.',
                $table,
                $column,
                $default,
                $extra
            ));
        }
    }
};
