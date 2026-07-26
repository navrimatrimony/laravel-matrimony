<?php

use App\Services\Gunamilan\GunamilanMasterData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One canonical key per yoni.
 *
 * `master_yonis` shipped the same 14 animals twice: the Sanskrit set
 * (`ashwa`, `gaja`, `mesha`, …) and, added later, an English set (`horse`,
 * `elephant`, `sheep`, …). Both were active, so both appeared in every
 * dropdown, and the two spellings of one animal compared as different values.
 *
 * That was not cosmetic. `master_nakshatra_attributes` — the autofill rule that
 * derives yoni from nakshatra — points at the SANSKRIT rows, while
 * `GunamilanService::YONI_ENEMY_PAIRS` was written in ENGLISH. The enemy-pair
 * rule therefore never fired for an autofilled profile, and a manually picked
 * `horse` against an autofilled `ashwa` failed the same-animal test. Yoni is
 * worth 4 of the 36 points, so 4 points were systematically wrong.
 *
 * Sanskrit wins as canonical because the derivation table already uses it.
 * This migration:
 *   1. repoints every FK (`profile_horoscope_data`, `master_nakshatra_attributes`)
 *      from a duplicate row to its canonical row,
 *   2. deactivates the duplicates so they leave the dropdowns,
 *   3. relabels the canonical rows to carry the familiar animal name
 *      ("Horse (Ashwa)" / "घोडा (अश्व)") so nothing gets harder to recognise.
 *
 * Duplicate rows are deactivated, never deleted — an FK may still point at one
 * from a backup or a replica, and {@see GunamilanMasterData::YONI_ALIASES}
 * keeps resolving retired spellings anyway.
 */
return new class extends Migration
{
    /** Canonical Sanskrit key => [English label, Marathi label]. */
    private const CANONICAL_LABELS = [
        'ashwa' => ['Horse (Ashwa)', 'घोडा (अश्व)'],
        'gaja' => ['Elephant (Gaja)', 'हत्ती (गज)'],
        'mesha' => ['Sheep (Mesha)', 'मेंढी (मेष)'],
        'sarpa' => ['Serpent (Sarpa)', 'साप (सर्प)'],
        'shwan' => ['Dog (Shwan)', 'कुत्रा (श्वान)'],
        'marjar' => ['Cat (Marjar)', 'मांजर (मार्जार)'],
        'mushak' => ['Rat (Mushak)', 'उंदीर (मूषक)'],
        'gau' => ['Cow (Gau)', 'गाय (गौ)'],
        'mahish' => ['Buffalo (Mahish)', 'म्हैस (महिष)'],
        'vyaghra' => ['Tiger (Vyaghra)', 'वाघ (व्याघ्र)'],
        'mrga' => ['Deer (Mriga)', 'हरीण (मृग)'],
        'vanar' => ['Monkey (Vanar)', 'वानर'],
        'nakul' => ['Mongoose (Nakul)', 'नेवाळा (नकुल)'],
        'singh' => ['Lion (Singh)', 'सिंह'],
    ];

    /** Tables holding a `yoni_id` FK that must follow the remap. */
    private const YONI_FK_TABLES = [
        'profile_horoscope_data' => 'yoni_id',
        'master_nakshatra_attributes' => 'yoni_id',
        'intake_submissions' => 'yoni_id',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('master_yonis')) {
            return;
        }

        $rows = DB::table('master_yonis')->get(['id', 'key']);

        /** @var array<string, int> $canonicalIds canonical key => owning row id */
        $canonicalIds = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row->key));
            if (in_array($key, GunamilanMasterData::CANONICAL_YONI_KEYS, true)) {
                $canonicalIds[$key] = (int) $row->id;
            }
        }

        // A deployment that never got the Sanskrit rows: promote the English row
        // by renaming it to the canonical key instead of orphaning every FK.
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row->key));
            $canonical = GunamilanMasterData::canonicalYoniKeyFor($key);
            if ($canonical === null || $key === $canonical || isset($canonicalIds[$canonical])) {
                continue;
            }
            DB::table('master_yonis')->where('id', $row->id)->update([
                'key' => $canonical,
                'updated_at' => now(),
            ]);
            $canonicalIds[$canonical] = (int) $row->id;
        }

        foreach ($rows as $row) {
            $duplicateId = (int) $row->id;
            $key = strtolower(trim((string) $row->key));
            $canonical = GunamilanMasterData::canonicalYoniKeyFor($key);

            if ($canonical === null || $key === $canonical) {
                continue;
            }

            $canonicalId = $canonicalIds[$canonical] ?? null;
            if ($canonicalId === null || $canonicalId === $duplicateId) {
                continue;
            }

            foreach (self::YONI_FK_TABLES as $table => $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }
                DB::table($table)->where($column, $duplicateId)->update([$column => $canonicalId]);
            }

            DB::table('master_yonis')->where('id', $duplicateId)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }

        $hasMr = Schema::hasColumn('master_yonis', 'label_mr');
        foreach (self::CANONICAL_LABELS as $key => [$label, $labelMr]) {
            $update = ['label' => $label, 'is_active' => true, 'updated_at' => now()];
            if ($hasMr) {
                $update['label_mr'] = $labelMr;
            }
            DB::table('master_yonis')->where('key', $key)->update($update);
        }

        app(GunamilanMasterData::class)->forget();
    }

    /**
     * Reactivate the duplicate rows only. The FK remap is NOT reversed: a
     * canonical id is correct under both schemas, and pointing rows back at a
     * duplicate would restore the scoring bug.
     */
    public function down(): void
    {
        if (! Schema::hasTable('master_yonis')) {
            return;
        }

        foreach (DB::table('master_yonis')->get(['id', 'key']) as $row) {
            $key = strtolower(trim((string) $row->key));
            $canonical = GunamilanMasterData::canonicalYoniKeyFor($key);
            if ($canonical !== null && $key !== $canonical) {
                DB::table('master_yonis')->where('id', $row->id)->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            }
        }

        app(GunamilanMasterData::class)->forget();
    }
};
