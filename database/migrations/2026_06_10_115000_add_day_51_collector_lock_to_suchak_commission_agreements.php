<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_commission_agreements', function (Blueprint $table): void {
            $table->unsignedBigInteger('collector_suchak_account_id')->nullable()->after('bride_side_suchak_account_id');
            $table->index('collector_suchak_account_id', 'suchak_commission_collector_idx');
            $table->foreign('collector_suchak_account_id', 'suchak_commission_collector_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
        });

        DB::table('suchak_commission_agreements')
            ->whereNull('collector_suchak_account_id')
            ->orderBy('id')
            ->chunkById(100, function ($agreements): void {
                $requestIds = $agreements
                    ->pluck('collaboration_request_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($requestIds === []) {
                    return;
                }

                $targetAccountIds = DB::table('suchak_collaboration_requests')
                    ->whereIn('id', $requestIds)
                    ->pluck('target_suchak_account_id', 'id');

                foreach ($agreements as $agreement) {
                    $collectorId = $targetAccountIds[(int) $agreement->collaboration_request_id]
                        ?? $agreement->bride_side_suchak_account_id
                        ?? $agreement->groom_side_suchak_account_id
                        ?? null;

                    if ($collectorId === null) {
                        continue;
                    }

                    DB::table('suchak_commission_agreements')
                        ->where('id', $agreement->id)
                        ->update(['collector_suchak_account_id' => (int) $collectorId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('suchak_commission_agreements', function (Blueprint $table): void {
            $table->dropForeign('suchak_commission_collector_fk');
            $table->dropIndex('suchak_commission_collector_idx');
            $table->dropColumn('collector_suchak_account_id');
        });
    }
};
