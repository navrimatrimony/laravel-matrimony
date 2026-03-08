<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds master_nakshatra_pada_rashi_rules (nakshatra_id + charan -> rashi_id).
 * Canonical nakshatra-pada-rashi mapping for horoscope dependency.
 * Rashi keys: mesha, vrishabha, mithuna, karka, simha, kanya, tula, vrishchika, dhanu, makara, kumbha, meena.
 */
class NakshatraPadaRashiRuleSeeder extends Seeder
{
    /** nakshatra key => [ charan_min, charan_max, rashi_key ] (in order; overlapping charans in separate entries) */
    private const CANONICAL = [
        'ashwini' => [[1, 4, 'mesha']],
        'bharani' => [[1, 4, 'mesha']],
        'krittika' => [[1, 1, 'mesha'], [2, 4, 'vrishabha']],
        'rohini' => [[1, 4, 'vrishabha']],
        'mrigashira' => [[1, 2, 'vrishabha'], [3, 4, 'mithuna']],
        'ardra' => [[1, 4, 'mithuna']],
        'punarvasu' => [[1, 3, 'mithuna'], [4, 4, 'karka']],
        'pushya' => [[1, 4, 'karka']],
        'ashlesha' => [[1, 4, 'karka']],
        'magha' => [[1, 4, 'simha']],
        'purva_phalguni' => [[1, 4, 'simha']],
        'uttara_phalguni' => [[1, 1, 'simha'], [2, 4, 'kanya']],
        'hasta' => [[1, 4, 'kanya']],
        'chitra' => [[1, 2, 'kanya'], [3, 4, 'tula']],
        'swati' => [[1, 4, 'tula']],
        'vishakha' => [[1, 3, 'tula'], [4, 4, 'vrishchika']],
        'anuradha' => [[1, 4, 'vrishchika']],
        'jyeshtha' => [[1, 4, 'vrishchika']],
        'mula' => [[1, 4, 'dhanu']],
        'purva_ashadha' => [[1, 4, 'dhanu']],
        'uttara_ashadha' => [[1, 1, 'dhanu'], [2, 4, 'makara']],
        'shravana' => [[1, 4, 'makara']],
        'dhanishta' => [[1, 2, 'makara'], [3, 4, 'kumbha']],
        'shatabhisha' => [[1, 4, 'kumbha']],
        'purva_bhadrapada' => [[1, 3, 'kumbha'], [4, 4, 'meena']],
        'uttara_bhadrapada' => [[1, 4, 'meena']],
        'revati' => [[1, 4, 'meena']],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('master_nakshatra_pada_rashi_rules')) {
            return;
        }
        $nakshatras = DB::table('master_nakshatras')->where('is_active', true)->pluck('id', 'key');
        $rashis = DB::table('master_rashis')->where('is_active', true)->pluck('id', 'key');
        if ($nakshatras->isEmpty() || $rashis->isEmpty()) {
            return;
        }

        foreach (self::CANONICAL as $nakshatraKey => $ranges) {
            $nakshatraId = $nakshatras->get($nakshatraKey);
            if (! $nakshatraId) {
                continue;
            }
            foreach ($ranges as [$cMin, $cMax, $rashiKey]) {
                $rashiId = $rashis->get($rashiKey);
                if (! $rashiId) {
                    continue;
                }
                for ($charan = $cMin; $charan <= $cMax; $charan++) {
                    DB::table('master_nakshatra_pada_rashi_rules')->updateOrInsert(
                        ['nakshatra_id' => $nakshatraId, 'charan' => $charan],
                        ['rashi_id' => $rashiId, 'is_active' => true, 'updated_at' => now()]
                    );
                }
            }
        }
    }
}
