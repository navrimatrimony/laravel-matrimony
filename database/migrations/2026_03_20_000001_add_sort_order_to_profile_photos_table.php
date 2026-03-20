<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $indexName = 'profile_photos_profile_id_sort_order_idx';

    public function up(): void
    {
        if (! Schema::hasTable('profile_photos')) {
            return;
        }

        if (Schema::hasColumn('profile_photos', 'sort_order')) {
            return;
        }

        Schema::table('profile_photos', function (Blueprint $table) {
            $table->integer('sort_order')
                ->nullable()
                ->default(0)
                ->after('is_primary');
        });

        // Backfill existing rows per profile in stable order:
        // primary first (is_primary desc), then created_at asc, then id asc.
        $profileIds = DB::table('profile_photos')->distinct()->pluck('profile_id');
        foreach ($profileIds as $profileId) {
            $rows = DB::table('profile_photos')
                ->where('profile_id', $profileId)
                ->orderByDesc('is_primary')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id']);

            $i = 0;
            foreach ($rows as $row) {
                DB::table('profile_photos')
                    ->where('id', (int) $row->id)
                    ->update(['sort_order' => $i]);
                $i++;
            }
        }

        Schema::table('profile_photos', function (Blueprint $table) {
            $table->index(['profile_id', 'sort_order'], $this->indexName);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_photos')) {
            return;
        }

        if (Schema::hasColumn('profile_photos', 'sort_order')) {
            Schema::table('profile_photos', function (Blueprint $table) {
                // dropIndex() will no-op if index does not exist (depending on driver), but keeps rollback safe.
                $table->dropIndex($this->indexName);
                $table->dropColumn('sort_order');
            });
        }
    }
};

