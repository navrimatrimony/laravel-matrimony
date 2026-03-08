<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds master_nakshatra_attributes (nakshatra -> gan_id, nadi_id, yoni_id).
 * Yoni depends only on nakshatra; plain yoni keys (no male/female). Profile entry scope only.
 */
class NakshatraAttributesSeeder extends Seeder
{
    /** nakshatra key => [gan_key, nadi_key, yoni_key]. Yoni: single plain name per nakshatra. */
    private const CANONICAL = [
        'ashwini' => ['deva', 'adi', 'horse'],
        'bharani' => ['manav', 'madhya', 'elephant'],
        'krittika' => ['rakshasa', 'antya', 'sheep'],
        'rohini' => ['manav', 'antya', 'serpent'],
        'mrigashira' => ['deva', 'madhya', 'serpent'],
        'ardra' => ['manav', 'adi', 'dog'],
        'punarvasu' => ['deva', 'adi', 'cat'],
        'pushya' => ['deva', 'madhya', 'sheep'],
        'ashlesha' => ['rakshasa', 'antya', 'cat'],
        'magha' => ['rakshasa', 'antya', 'rat'],
        'purva_phalguni' => ['manav', 'madhya', 'rat'],
        'uttara_phalguni' => ['manav', 'adi', 'cow'],
        'hasta' => ['deva', 'adi', 'buffalo'],
        'chitra' => ['rakshasa', 'madhya', 'tiger'],
        'swati' => ['deva', 'antya', 'buffalo'],
        'vishakha' => ['rakshasa', 'antya', 'tiger'],
        'anuradha' => ['deva', 'madhya', 'deer'],
        'jyeshtha' => ['rakshasa', 'adi', 'deer'],
        'mula' => ['rakshasa', 'adi', 'dog'],
        'purva_ashadha' => ['manav', 'madhya', 'monkey'],
        'uttara_ashadha' => ['manav', 'antya', 'mongoose'],
        'shravana' => ['deva', 'antya', 'monkey'],
        'dhanishta' => ['rakshasa', 'madhya', 'lion'],
        'shatabhisha' => ['rakshasa', 'adi', 'horse'],
        'purva_bhadrapada' => ['manav', 'adi', 'lion'],
        'uttara_bhadrapada' => ['manav', 'madhya', 'cow'],
        'revati' => ['deva', 'antya', 'elephant'],
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
