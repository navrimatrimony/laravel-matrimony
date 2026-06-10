<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        $this->dedupeActiveSubscriptions();

        DB::statement('DROP INDEX IF EXISTS subscriptions_one_active_user');
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_active_user ON subscriptions (user_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS subscriptions_one_active_user');
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
