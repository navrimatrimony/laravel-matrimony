<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * At most one row per user with status = active (DB-enforced on SQLite & PostgreSQL).
     * Other drivers: rely on {@see \App\Models\Subscription} saving hook.
     */
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        $this->dedupeActiveSubscriptions();

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS subscriptions_one_active_user ON subscriptions (user_id) WHERE status = \'active\'');
        }

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS subscriptions_one_active_user ON subscriptions (user_id) WHERE (status = \'active\')');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS subscriptions_one_active_user');
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS subscriptions_one_active_user');
        }
    }

    private function dedupeActiveSubscriptions(): void
    {
        $dupUserIds = DB::table('subscriptions')
            ->select('user_id')
            ->where('status', 'active')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($dupUserIds as $userId) {
            $keepId = DB::table('subscriptions')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->value('id');

            if ($keepId === null) {
                continue;
            }

            DB::table('subscriptions')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->where('id', '!=', $keepId)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
        }
    }
};
