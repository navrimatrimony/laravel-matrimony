<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FCM registration tokens, one row per physical device install.
 *
 * `tokenable` is polymorphic on purpose: a member device points at `App\Models\User`,
 * a Suchak device at `App\Models\SuchakAccount`. There is no morph map in this
 * codebase, so the FQCN is stored verbatim.
 *
 * `token` is globally UNIQUE, not unique-per-owner. FCM issues one registration
 * token per app install, and that same physical device can be handed to a
 * different account (logout → login, shared family phone). Re-registering an
 * existing token therefore RE-POINTS the row at the new owner instead of
 * inserting a second row — otherwise the previous owner keeps receiving pushes
 * on a phone they no longer control. See DeviceTokenService::register().
 *
 * TIMESTAMP authorship: this schema's hard rule is that the database never
 * authors a timestamp (see tests/Unit/DatabaseGeneratedTimestampGuardTest, whose
 * allow-list is deliberately empty). `last_seen_at` is nullable, which is already
 * enough to keep MySQL from attaching the implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` to the first NOT NULL
 * TIMESTAMP column, but a server running with `explicit_defaults_for_timestamp = OFF`
 * is exactly the environment that produced the messages.sent_at corruption. The
 * normalisation pass below re-asserts application authorship after create, so this
 * table cannot break the guard however the server is configured.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_tokens')) {
            return;
        }

        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();
            // tokenable_type + tokenable_id (+ composite index).
            $table->morphs('tokenable');
            // 'member' | 'suchak' — which app produced the token. App-level enum
            // via DeviceToken consts, not a DB enum (matches suchak_customer_plans).
            $table->string('app', 16);
            // Android FCM tokens are ~160 chars today; 512 leaves headroom and stays
            // well inside InnoDB's 3072-byte key limit at utf8mb4 (512 * 4 = 2048).
            $table->string('token', 512);
            $table->string('platform', 16)->default('android');
            // Application-authored (DeviceTokenService), never DB-authored.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('token', 'device_tokens_token_unique');
            $table->index(['tokenable_type', 'tokenable_id', 'app'], 'device_tokens_owner_app_idx');
        });

        $this->stopDatabaseFromAuthoringTimestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }

    /**
     * Re-assert application authorship for every timestamp column of this table.
     * Idempotent and a no-op on a correctly configured server.
     */
    private function stopDatabaseFromAuthoringTimestamps(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ([['last_seen_at', true], ['created_at', true], ['updated_at', true]] as [$column, $nullable]) {
            if (! $this->isDatabaseGenerated($column)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `device_tokens` MODIFY `%s` TIMESTAMP %s',
                $column,
                $nullable ? 'NULL DEFAULT NULL' : 'NOT NULL'
            ));

            if ($this->isDatabaseGenerated($column)) {
                throw new RuntimeException(sprintf(
                    'device_tokens.%s is still database-generated. This server runs with '
                    .'explicit_defaults_for_timestamp = OFF; set it to ON (my.cnf) and re-run.',
                    $column
                ));
            }
        }
    }

    private function isDatabaseGenerated(string $column): bool
    {
        $row = DB::selectOne(
            'SELECT COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['device_tokens', $column]
        );

        if (! $row) {
            return false;
        }

        return str_contains(strtolower((string) ($row->EXTRA ?? '')), 'on update')
            || str_contains(strtoupper((string) ($row->COLUMN_DEFAULT ?? '')), 'CURRENT_TIMESTAMP');
    }
};
