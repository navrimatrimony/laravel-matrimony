<?php

use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every EXISTING Suchak the two ready-made plans as rows they own.
 *
 * Until now the ready-made plans were code, not data: App\Modules\Suchak\Support\
 * SuchakDefaultPlans held them, and a suchak_customer_plans row with a preset_key
 * was only a thin OVERRIDE carrying price / name / visibility / order. Nothing
 * could ever write the four fee columns onto such a row, so a Suchak could not
 * edit a ready-made plan at all.
 *
 * New accounts do not need this migration — SuchakCustomerPlanService::
 * ensurePresetRows() seeds them lazily on the first read of their plans, which is
 * also what catches any account this migration misses. This runs so an account
 * that ALREADY has a half-filled override row ends up with the same complete row
 * a new account gets.
 *
 * IDEMPOTENT, in both halves:
 *   - existing preset rows: only columns that are still NULL are filled, so a
 *     price a Suchak already changed is never reset;
 *   - missing preset rows: inserted with insertOrIgnore against the
 *     (suchak_account_id, preset_key) unique index.
 *
 * WHAT A RE-RUN CANNOT RECOVER, stated plainly: NULL is used as the marker for
 * "never filled in", and a Suchak deliberately CLEARING a field lands on the same
 * NULL. So a Suchak who removes the Marathi name from their Basic plan and then
 * has this migration re-run will get the ready-made Marathi name back. Prices,
 * durations, fees, notes and visibility are not exposed to that: price is only
 * touched when NULL, and duration / the four fees / private_note / is_visible are
 * never written here at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suchak_customer_plans') || ! Schema::hasTable('suchak_accounts')) {
            return;
        }

        $now = now();

        foreach (SuchakDefaultPlans::seedRows() as $seed) {
            $this->backfillExistingRows($seed, $now);
        }

        $this->insertMissingRows(SuchakDefaultPlans::seedRows(), $now);
    }

    /**
     * Fill only the holes an old override row left. Every update is guarded by
     * whereNull, so nothing a Suchak has set is overwritten.
     *
     * @param  array<string, mixed>  $seed
     */
    private function backfillExistingRows(array $seed, mixed $now): void
    {
        $columns = [
            'name' => $seed['name'],
            'name_mr' => $seed['name_mr'],
            'price_amount' => $seed['price_amount'],
            'currency' => $seed['currency'],
            'services_json' => json_encode($seed['services_json']),
        ];

        foreach ($columns as $column => $value) {
            if ($value === null || ! Schema::hasColumn('suchak_customer_plans', $column)) {
                continue;
            }

            DB::table('suchak_customer_plans')
                ->where('preset_key', $seed['preset_key'])
                ->whereNull($column)
                ->update([$column => $value, 'updated_at' => $now]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $seeds
     */
    private function insertMissingRows(array $seeds, mixed $now): void
    {
        DB::table('suchak_accounts')
            ->select('id')
            ->chunkById(500, function ($accounts) use ($seeds, $now): void {
                $accountIds = collect($accounts)->pluck('id')->all();

                $existing = DB::table('suchak_customer_plans')
                    ->whereIn('suchak_account_id', $accountIds)
                    ->whereNotNull('preset_key')
                    ->get(['suchak_account_id', 'preset_key'])
                    ->map(static fn ($row): string => $row->suchak_account_id.'|'.$row->preset_key)
                    ->all();

                $rows = [];
                foreach ($accountIds as $accountId) {
                    foreach ($seeds as $seed) {
                        if (in_array($accountId.'|'.$seed['preset_key'], $existing, true)) {
                            continue;
                        }

                        $rows[] = [
                            'suchak_account_id' => $accountId,
                            'preset_key' => $seed['preset_key'],
                            'name' => $seed['name'],
                            'name_mr' => $seed['name_mr'],
                            'price_amount' => $seed['price_amount'],
                            'currency' => $seed['currency'],
                            // A ready-made plan fixes no duration and no fee until
                            // the Suchak fixes one. NULL is the honest answer.
                            'duration' => null,
                            'services_json' => json_encode($seed['services_json']),
                            'is_visible' => true,
                            'sort_order' => $seed['sort_order'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('suchak_customer_plans')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Remove ONLY the rows this migration could have created and that no Suchak
     * has since made their own: still exactly the seed content, still carrying no
     * duration, no fee, no discount, no note, still visible.
     *
     * What this deliberately does NOT do, because it would destroy real work:
     *   - it keeps any preset row a Suchak has edited (a changed price, a fee, a
     *     renamed plan, a hidden plan). After a down() those rows stay, and they
     *     are exactly what the old code path expected anyway — a preset row that
     *     overrides price / name / visibility / order;
     *   - it cannot un-backfill the columns filled above, because the fill is
     *     indistinguishable from a Suchak typing the same values. They stay.
     */
    public function down(): void
    {
        if (! Schema::hasTable('suchak_customer_plans')) {
            return;
        }

        foreach (SuchakDefaultPlans::seedRows() as $seed) {
            DB::table('suchak_customer_plans')
                ->where('preset_key', $seed['preset_key'])
                ->where('name', $seed['name'])
                ->where('price_amount', $seed['price_amount'])
                ->where('is_visible', true)
                ->whereNull('duration')
                ->whereNull('per_meeting_fee_amount')
                ->whereNull('per_meeting_online_fee_amount')
                ->whereNull('post_marriage_fee_mode')
                ->whereNull('post_marriage_fee_amount')
                ->whereNull('original_price_amount')
                ->whereNull('private_note')
                ->delete();
        }
    }
};
