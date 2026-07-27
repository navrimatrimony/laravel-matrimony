<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recurrence guard for the class of bug fixed by
 *   2026_07_27_160000_stop_database_from_authoring_chat_timestamps
 *   2026_07_27_170000_stop_database_from_authoring_governance_and_consent_timestamps
 *
 * A plain `$table->timestamp('x')` (NOT NULL, no default) is enough for MySQL to
 * silently attach `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` to the
 * first such column of a table, whenever the server runs with
 * `explicit_defaults_for_timestamp = OFF`. From then on every UPDATE of the row
 * rewrites that column — evaluated in the MySQL session zone (UTC), not the app
 * zone (Asia/Kolkata). It corrupted `messages.sent_at` in production before
 * anyone noticed, and it was one revoke away from expiring live `contact_grants`.
 *
 * Both checks are read-only. This file deliberately lives in tests/Unit and does
 * NOT use RefreshDatabase (tests/Feature auto-applies it via tests/Pest.php),
 * so it is safe to point at a real database:
 *
 *   DB_CONNECTION=mysql php artisan test --filter=DatabaseGeneratedTimestampGuard
 *
 * On the default sqlite test connection there is no such behaviour and both
 * tests skip.
 */
class DatabaseGeneratedTimestampGuardTest extends TestCase
{
    /**
     * Columns where `ON UPDATE CURRENT_TIMESTAMP` is genuinely wanted, as
     * `table.column`.
     *
     * Deliberately empty: no column in this schema wants the database to author
     * its value. Adding an entry is a business decision — it asserts that the DB
     * clock, in the DB's own time zone, is the correct author of that value.
     * That is true for none of our tables today.
     *
     * @var list<string>
     */
    private const ALLOW_LIST = [];

    #[Test]
    public function no_column_is_silently_rewritten_by_the_database_on_update(): void
    {
        $this->skipUnlessMySql();

        $offenders = [];

        foreach (DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND EXTRA LIKE '%on update%'
             ORDER BY TABLE_NAME, COLUMN_NAME"
        ) as $row) {
            $column = $row->TABLE_NAME.'.'.$row->COLUMN_NAME;

            if (! in_array($column, self::ALLOW_LIST, true)) {
                $offenders[] = $column;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These columns carry ON UPDATE CURRENT_TIMESTAMP, so any UPDATE to the row silently\n"
            ."rewrites them, in the MySQL session time zone rather than the app's:\n\n  %s\n\n"
            ."The usual cause is a bare \$table->timestamp('x') in a recent migration. Fix it with\n"
            ."a migration modelled on database/migrations/2026_07_27_170000_stop_database_from_\n"
            ."authoring_governance_and_consent_timestamps.php. If the database really should author\n"
            .'the value, add the column to self::ALLOW_LIST with a reason.',
            implode("\n  ", $offenders)
        ));
    }

    #[Test]
    public function server_does_not_hand_out_timestamp_auto_columns_to_new_tables(): void
    {
        $this->skipUnlessMySql();

        $setting = DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS value');

        $this->assertSame(1, (int) ($setting->value ?? 0),
            "explicit_defaults_for_timestamp is OFF on this server.\n\n"
            ."Every new table's first NOT NULL TIMESTAMP column will silently be created with\n"
            ."DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, re-introducing the bug that\n"
            ."corrupted messages.sent_at. Set explicit_defaults_for_timestamp = ON in my.cnf and\n"
            .'restart MySQL; the two fix migrations already depend on it being ON.'
        );
    }

    private function skipUnlessMySql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Implicit TIMESTAMP auto-columns are a MySQL/MariaDB behaviour. '
                .'Run with DB_CONNECTION=mysql to check a real schema.'
            );
        }
    }
}
