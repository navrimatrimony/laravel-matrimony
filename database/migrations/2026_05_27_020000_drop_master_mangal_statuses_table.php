<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_horoscope_data') && Schema::hasColumn('profile_horoscope_data', 'mangal_status_id')) {
            $this->backfillMangalDoshTypeFromStatus();

            Schema::table('profile_horoscope_data', function (Blueprint $table) {
                if ($this->foreignKeyExists('profile_horoscope_data', 'profile_horoscope_data_mangal_status_id_foreign')) {
                    $table->dropForeign('profile_horoscope_data_mangal_status_id_foreign');
                }
                $table->dropColumn('mangal_status_id');
            });
        }

        Schema::dropIfExists('master_mangal_statuses');
    }

    public function down(): void
    {
        // Intentionally irreversible: mangal status is consolidated into master_mangal_dosh_types.
    }

    private function backfillMangalDoshTypeFromStatus(): void
    {
        if (! Schema::hasTable('master_mangal_statuses') || ! Schema::hasTable('master_mangal_dosh_types')) {
            return;
        }

        $targetIds = DB::table('master_mangal_dosh_types')
            ->whereIn('key', ['bhumangal', 'none', 'don_t_know'])
            ->pluck('id', 'key');

        $statusToDoshKey = [
            'yes' => 'bhumangal',
            'no' => 'none',
            'don_t_know' => 'don_t_know',
        ];

        foreach ($statusToDoshKey as $statusKey => $doshKey) {
            $targetId = $targetIds[$doshKey] ?? null;
            if (! $targetId) {
                continue;
            }

            DB::table('profile_horoscope_data as horoscope')
                ->join('master_mangal_statuses as statuses', 'horoscope.mangal_status_id', '=', 'statuses.id')
                ->where('statuses.key', $statusKey)
                ->whereNull('horoscope.mangal_dosh_type_id')
                ->update(['horoscope.mangal_dosh_type_id' => (int) $targetId]);
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return count($connection->select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND CONSTRAINT_TYPE = ?
                AND CONSTRAINT_NAME = ?',
            [$database, $table, 'FOREIGN KEY', $name]
        )) > 0;
    }
};
