<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * RETIRED — deliberately a no-op. Do not restore its body.
 *
 * This seeder used to add male/female polarity rows to `master_yonis`
 * (`horse_male`, `horse_female`, `elephant_male`, …) on top of the plain
 * animals. That is a third spelling of a concept that already existed twice,
 * and it violates the frozen no-duplicate rule: one fact, one canonical key.
 *
 * `master_yonis` now holds exactly 14 canonical Sanskrit keys (plus the `other`
 * sentinel), which `master_nakshatra_attributes` derives from and
 * `App\Services\Gunamilan\GunamilanService` scores on. Yoni Kuta as this product
 * implements it needs the animal only; polarity would have to arrive as a
 * separate attribute on the canonical row, never as extra rows.
 *
 * It was never registered in `DatabaseSeeder`, so nothing depended on it. The
 * class is kept rather than deleted so a stray `--class=` invocation is a
 * harmless no-op instead of a fatal error that tempts someone to re-add it.
 *
 * @see \App\Services\Gunamilan\GunamilanMasterData::CANONICAL_YONI_KEYS
 */
class YoniPolaritySeeder extends Seeder
{
    public function run(): void
    {
        // Intentionally empty. See the class docblock.
    }
}
