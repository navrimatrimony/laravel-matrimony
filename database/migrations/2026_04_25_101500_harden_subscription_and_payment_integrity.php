<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('subscriptions') && $driver === 'mysql') {
            // MySQL lacks partial unique indexes; enforce "only one active row per user" via generated nullable key.
            if (! Schema::hasColumn('subscriptions', 'active_user_guard')) {
                DB::statement("ALTER TABLE subscriptions ADD COLUMN active_user_guard BIGINT GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN user_id ELSE NULL END) STORED");
            }
            if (! $this->mysqlIndexExists('subscriptions', 'subscriptions_one_active_user_mysql')) {
                DB::statement('CREATE UNIQUE INDEX subscriptions_one_active_user_mysql ON subscriptions (active_user_guard)');
            }
        }

        if (Schema::hasTable('payments')) {
            $this->dedupeByTxnid('payments', 'txnid');
            if ($driver === 'mysql') {
                if (! $this->mysqlIndexExists('payments', 'payments_txnid_unique')) {
                    DB::statement('ALTER TABLE payments ADD UNIQUE KEY payments_txnid_unique (txnid)');
                }
            } else {
                if (! $this->indexExists('payments', 'payments_txnid_unique')) {
                    if ($driver === 'sqlite') {
                        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS payments_txnid_unique ON payments (txnid)');
                    } else {
                        Schema::table('payments', function (Blueprint $table) {
                            $table->unique('txnid', 'payments_txnid_unique');
                        });
                    }
                }
            }

            if (Schema::hasColumn('payments', 'payu_txnid')) {
                $this->dedupeByTxnid('payments', 'payu_txnid');
                if ($driver === 'mysql') {
                    if (! $this->mysqlIndexExists('payments', 'payments_payu_txnid_unique')) {
                        DB::statement('ALTER TABLE payments ADD UNIQUE KEY payments_payu_txnid_unique (payu_txnid)');
                    }
                } else {
                    if (! $this->indexExists('payments', 'payments_payu_txnid_unique')) {
                        if ($driver === 'sqlite') {
                            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS payments_payu_txnid_unique ON payments (payu_txnid)');
                        } else {
                            Schema::table('payments', function (Blueprint $table) {
                                $table->unique('payu_txnid', 'payments_payu_txnid_unique');
                            });
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('subscriptions') && $driver === 'mysql') {
            if ($this->mysqlIndexExists('subscriptions', 'subscriptions_one_active_user_mysql')) {
                DB::statement('ALTER TABLE subscriptions DROP INDEX subscriptions_one_active_user_mysql');
            }
            if (Schema::hasColumn('subscriptions', 'active_user_guard')) {
                DB::statement('ALTER TABLE subscriptions DROP COLUMN active_user_guard');
            }
        }

        if (Schema::hasTable('payments')) {
            if ($driver === 'mysql') {
                if ($this->mysqlIndexExists('payments', 'payments_txnid_unique')) {
                    DB::statement('ALTER TABLE payments DROP INDEX payments_txnid_unique');
                }
                if (Schema::hasColumn('payments', 'payu_txnid') && $this->mysqlIndexExists('payments', 'payments_payu_txnid_unique')) {
                    DB::statement('ALTER TABLE payments DROP INDEX payments_payu_txnid_unique');
                }
            } else {
                if ($this->indexExists('payments', 'payments_txnid_unique')) {
                    if ($driver === 'sqlite') {
                        DB::statement('DROP INDEX IF EXISTS payments_txnid_unique');
                    } else {
                        Schema::table('payments', function (Blueprint $table) {
                            $table->dropUnique('payments_txnid_unique');
                        });
                    }
                }
                if (Schema::hasColumn('payments', 'payu_txnid') && $this->indexExists('payments', 'payments_payu_txnid_unique')) {
                    if ($driver === 'sqlite') {
                        DB::statement('DROP INDEX IF EXISTS payments_payu_txnid_unique');
                    } else {
                        Schema::table('payments', function (Blueprint $table) {
                            $table->dropUnique('payments_payu_txnid_unique');
                        });
                    }
                }
            }
        }
    }

    private function dedupeByTxnid(string $table, string $column): void
    {
        $dups = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        foreach ($dups as $txnid) {
            $rows = DB::table($table)
                ->where($column, $txnid)
                ->orderByDesc('id')
                ->pluck('id')
                ->values();

            if ($rows->count() <= 1) {
                continue;
            }

            $keepId = (int) $rows->first();
            $dropIds = $rows->slice(1);
            foreach ($dropIds as $id) {
                DB::table($table)
                    ->where('id', (int) $id)
                    ->update([
                        $column => (string) $txnid.'#dup#'.(int) $id,
                        'updated_at' => now(),
                    ]);
            }

            logger()->warning('payment_txnid_deduplicated_before_unique_index', [
                'table' => $table,
                'column' => $column,
                'txnid' => (string) $txnid,
                'kept_id' => $keepId,
                'renamed_ids' => $dropIds->map(fn ($v) => (int) $v)->all(),
            ]);
        }
    }

    private function mysqlIndexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );

        return ((int) ($row->c ?? 0)) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            return $this->mysqlIndexExists($table, $index);
        }
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('".$table."')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $index) {
                    return true;
                }
            }

            return false;
        }
        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT COUNT(1) AS c FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index]
            );

            return ((int) ($row->c ?? 0)) > 0;
        }

        return false;
    }
};

