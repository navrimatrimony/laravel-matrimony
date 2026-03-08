<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Extends master_yonis with male/female polarity for Yoni Kuta scoring.
 * Do NOT collapse horse_male vs horse_female etc. Additive only; existing keys unchanged.
 */
class YoniPolaritySeeder extends Seeder
{
    public function run(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('master_yonis')) {
            return;
        }

        $rows = [
            ['key' => 'horse_male', 'label' => 'Horse (Male)'],
            ['key' => 'horse_female', 'label' => 'Horse (Female)'],
            ['key' => 'elephant_male', 'label' => 'Elephant (Male)'],
            ['key' => 'elephant_female', 'label' => 'Elephant (Female)'],
            ['key' => 'sheep_female', 'label' => 'Sheep (Female)'],
            ['key' => 'serpent_male', 'label' => 'Serpent (Male)'],
            ['key' => 'serpent_female', 'label' => 'Serpent (Female)'],
            ['key' => 'dog_male', 'label' => 'Dog (Male)'],
            ['key' => 'dog_female', 'label' => 'Dog (Female)'],
            ['key' => 'cat_male', 'label' => 'Cat (Male)'],
            ['key' => 'cat_female', 'label' => 'Cat (Female)'],
            ['key' => 'goat_male', 'label' => 'Goat (Male)'],
            ['key' => 'rat_male', 'label' => 'Rat (Male)'],
            ['key' => 'rat_female', 'label' => 'Rat (Female)'],
            ['key' => 'cow_male', 'label' => 'Cow (Male)'],
            ['key' => 'cow_female', 'label' => 'Cow (Female)'],
            ['key' => 'buffalo_male', 'label' => 'Buffalo (Male)'],
            ['key' => 'buffalo_female', 'label' => 'Buffalo (Female)'],
            ['key' => 'tiger_male', 'label' => 'Tiger (Male)'],
            ['key' => 'tiger_female', 'label' => 'Tiger (Female)'],
            ['key' => 'deer_male', 'label' => 'Deer (Male)'],
            ['key' => 'deer_female', 'label' => 'Deer (Female)'],
            ['key' => 'monkey_male', 'label' => 'Monkey (Male)'],
            ['key' => 'monkey_female', 'label' => 'Monkey (Female)'],
            ['key' => 'mongoose_male', 'label' => 'Mongoose (Male)'],
            ['key' => 'lion_male', 'label' => 'Lion (Male)'],
            ['key' => 'lion_female', 'label' => 'Lion (Female)'],
        ];

        foreach ($rows as $row) {
            DB::table('master_yonis')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
