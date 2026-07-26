<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds master_nakshatra_attributes (nakshatra -> gan_id, nadi_id, yoni_id).
 * Yoni depends only on nakshatra. Profile entry scope only.
 *
 * Yoni keys here are the CANONICAL Sanskrit ones. This table is the reason
 * Sanskrit is canonical — it is the derivation authority every autofilled
 * profile inherits from. It used to be written against the English duplicate
 * rows while the live rows were Sanskrit; see
 * `2026_07_26_100100_normalize_master_yoni_canonical_keys`.
 */
class NakshatraAttributesSeeder extends Seeder
{
    /** nakshatra key => [gan_key, nadi_key, canonical yoni_key]. */
    private const CANONICAL = [
        'ashwini' => ['deva', 'adi', 'ashwa'],
        'bharani' => ['manav', 'madhya', 'gaja'],
        'krittika' => ['rakshasa', 'antya', 'mesha'],
        'rohini' => ['manav', 'antya', 'sarpa'],
        'mrigashira' => ['deva', 'madhya', 'sarpa'],
        'ardra' => ['manav', 'adi', 'shwan'],
        'punarvasu' => ['deva', 'adi', 'marjar'],
        'pushya' => ['deva', 'madhya', 'mesha'],
        'ashlesha' => ['rakshasa', 'antya', 'marjar'],
        'magha' => ['rakshasa', 'antya', 'mushak'],
        'purva_phalguni' => ['manav', 'madhya', 'mushak'],
        'uttara_phalguni' => ['manav', 'adi', 'gau'],
        'hasta' => ['deva', 'adi', 'mahish'],
        'chitra' => ['rakshasa', 'madhya', 'vyaghra'],
        'swati' => ['deva', 'antya', 'mahish'],
        'vishakha' => ['rakshasa', 'antya', 'vyaghra'],
        'anuradha' => ['deva', 'madhya', 'mrga'],
        'jyeshtha' => ['rakshasa', 'adi', 'mrga'],
        'mula' => ['rakshasa', 'adi', 'shwan'],
        'purva_ashadha' => ['manav', 'madhya', 'vanar'],
        'uttara_ashadha' => ['manav', 'antya', 'nakul'],
        'shravana' => ['deva', 'antya', 'vanar'],
        'dhanishta' => ['rakshasa', 'madhya', 'singh'],
        'shatabhisha' => ['rakshasa', 'adi', 'ashwa'],
        'purva_bhadrapada' => ['manav', 'adi', 'singh'],
        'uttara_bhadrapada' => ['manav', 'madhya', 'gau'],
        'revati' => ['deva', 'antya', 'gaja'],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('master_nakshatra_attributes')) {
            return;
        }
        $nakshatras = DB::table('master_nakshatras')->where('is_active', true)->pluck('id', 'key');
        $gans = DB::table('master_gans')->where('is_active', true)->pluck('id', 'key');
        $nadis = DB::table('master_nadis')->where('is_active', true)->pluck('id', 'key');
        $yonis = DB::table('master_yonis')->where('is_active', true)->pluck('id', 'key');
        if ($nakshatras->isEmpty() || $gans->isEmpty() || $nadis->isEmpty() || $yonis->isEmpty()) {
            return;
        }

        foreach (self::CANONICAL as $nakshatraKey => $keys) {
            $nakshatraId = $nakshatras->get($nakshatraKey);
            if (! $nakshatraId) {
                continue;
            }
            $ganId = $gans->get($keys[0]);
            $nadiId = $nadis->get($keys[1]);
            $yoniId = $yonis->get($keys[2]);
            DB::table('master_nakshatra_attributes')->updateOrInsert(
                ['nakshatra_id' => $nakshatraId],
                [
                    'gan_id' => $ganId,
                    'nadi_id' => $nadiId,
                    'yoni_id' => $yoniId,
                    'is_active' => true,
                    'updated_at' => now(),
                ]
            );
        }
    }
}
