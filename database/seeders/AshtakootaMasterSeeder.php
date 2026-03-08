<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds Ashta-Koota master data: Varna, Vashya, Rashi Lords; updates master_rashis and master_nakshatras.
 * Used for 36 Gun Milan (no user inputs for these factors).
 */
class AshtakootaMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedVarnas();
        $this->seedVashyas();
        $this->seedRashiLords();
        $this->updateRashisWithAshtakoota();
        $this->updateNakshatrasWithNumber();
    }

    private function seedVarnas(): void
    {
        if (! Schema::hasTable('master_varnas')) {
            return;
        }
        $rows = [
            ['key' => 'brahmin', 'label' => 'Brahmin'],
            ['key' => 'kshatriya', 'label' => 'Kshatriya'],
            ['key' => 'vaishya', 'label' => 'Vaishya'],
            ['key' => 'shudra', 'label' => 'Shudra'],
        ];
        foreach ($rows as $row) {
            DB::table('master_varnas')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedVashyas(): void
    {
        if (! Schema::hasTable('master_vashyas')) {
            return;
        }
        $rows = [
            ['key' => 'chatushpada', 'label' => 'Chatushpada'],
            ['key' => 'manav', 'label' => 'Manav'],
            ['key' => 'jalachar', 'label' => 'Jalachar'],
            ['key' => 'vanchar', 'label' => 'Vanchar'],
            ['key' => 'keet', 'label' => 'Keet'],
        ];
        foreach ($rows as $row) {
            DB::table('master_vashyas')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedRashiLords(): void
    {
        if (! Schema::hasTable('master_rashi_lords')) {
            return;
        }
        $rows = [
            ['key' => 'sun', 'label' => 'Sun'],
            ['key' => 'moon', 'label' => 'Moon'],
            ['key' => 'mars', 'label' => 'Mars'],
            ['key' => 'mercury', 'label' => 'Mercury'],
            ['key' => 'venus', 'label' => 'Venus'],
            ['key' => 'jupiter', 'label' => 'Jupiter'],
            ['key' => 'saturn', 'label' => 'Saturn'],
        ];
        foreach ($rows as $row) {
            DB::table('master_rashi_lords')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    /** Rashi key => [varna_key, vashya_key, rashi_lord_key]. Mesh=Kshatriya,Chatushpada,Mars; ... */
    private const RASHI_ASHTAKOOTA = [
        'mesha' => ['kshatriya', 'chatushpada', 'mars'],
        'vrishabha' => ['vaishya', 'chatushpada', 'venus'],
        'mithuna' => ['shudra', 'manav', 'mercury'],
        'karka' => ['brahmin', 'jalachar', 'moon'],
        'simha' => ['kshatriya', 'vanchar', 'sun'],
        'kanya' => ['vaishya', 'manav', 'mercury'],
        'tula' => ['shudra', 'manav', 'venus'],
        'vrishchika' => ['brahmin', 'keet', 'mars'],
        'dhanu' => ['kshatriya', 'chatushpada', 'jupiter'],
        'makara' => ['vaishya', 'chatushpada', 'saturn'],
        'kumbha' => ['shudra', 'manav', 'saturn'],
        'meena' => ['brahmin', 'jalachar', 'jupiter'],
    ];

    private function updateRashisWithAshtakoota(): void
    {
        if (! Schema::hasTable('master_rashis') || ! Schema::hasColumn('master_rashis', 'varna_id')) {
            return;
        }
        $varnas = DB::table('master_varnas')->where('is_active', true)->pluck('id', 'key');
        $vashyas = DB::table('master_vashyas')->where('is_active', true)->pluck('id', 'key');
        $lords = DB::table('master_rashi_lords')->where('is_active', true)->pluck('id', 'key');

        foreach (self::RASHI_ASHTAKOOTA as $rashiKey => $keys) {
            $varnaId = $varnas->get($keys[0]);
            $vashyaId = $vashyas->get($keys[1]);
            $lordId = $lords->get($keys[2]);
            DB::table('master_rashis')
                ->where('key', $rashiKey)
                ->update([
                    'varna_id' => $varnaId,
                    'vashya_id' => $vashyaId,
                    'rashi_lord_id' => $lordId,
                    'updated_at' => now(),
                ]);
        }
    }

    /** Nakshatra key => number 1-27 (order in zodiac). 'other' not numbered. */
    private const NAKSHATRA_NUMBERS = [
        'ashwini' => 1, 'bharani' => 2, 'krittika' => 3, 'rohini' => 4, 'mrigashira' => 5,
        'ardra' => 6, 'punarvasu' => 7, 'pushya' => 8, 'ashlesha' => 9, 'magha' => 10,
        'purva_phalguni' => 11, 'uttara_phalguni' => 12, 'hasta' => 13, 'chitra' => 14,
        'swati' => 15, 'vishakha' => 16, 'anuradha' => 17, 'jyeshtha' => 18, 'mula' => 19,
        'purva_ashadha' => 20, 'uttara_ashadha' => 21, 'shravana' => 22, 'dhanishta' => 23,
        'shatabhisha' => 24, 'purva_bhadrapada' => 25, 'uttara_bhadrapada' => 26, 'revati' => 27,
    ];

    private function updateNakshatrasWithNumber(): void
    {
        if (! Schema::hasTable('master_nakshatras') || ! Schema::hasColumn('master_nakshatras', 'nakshatra_number')) {
            return;
        }
        foreach (self::NAKSHATRA_NUMBERS as $key => $num) {
            DB::table('master_nakshatras')
                ->where('key', $key)
                ->update(['nakshatra_number' => $num, 'updated_at' => now()]);
        }
    }
}
