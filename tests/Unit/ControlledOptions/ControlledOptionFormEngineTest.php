<?php

namespace Tests\Unit\ControlledOptions;

use App\Services\ControlledOptions\ControlledOptionEngine;
use App\Services\ControlledOptions\ControlledOptionFormEngine;
use App\Services\ControlledOptions\ControlledOptionLabelResolver;
use App\Services\ControlledOptions\ControlledOptionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlledOptionFormEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeEngine(): ControlledOptionFormEngine
    {
        $registry = new ControlledOptionRegistry();
        $engine = new ControlledOptionEngine($registry);
        $labels = new ControlledOptionLabelResolver($registry);

        return new ControlledOptionFormEngine($registry, $labels, $engine);
    }

    public function test_get_options_returns_only_active_master_rows()
    {
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 1, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
            ['id' => 2, 'key' => 'madhya', 'label' => 'Madhya', 'is_active' => true],
            ['id' => 3, 'key' => 'antya', 'label' => 'Antya', 'is_active' => false],
        ]);

        $formEngine = $this->makeEngine();
        $options = $formEngine->getOptions('horoscope.nadi');

        $this->assertCount(2, $options);
        $keys = array_column($options, 'key');
        $this->assertContains('adi', $keys);
        $this->assertContains('madhya', $keys);
        $this->assertNotContains('antya', $keys);
    }

    public function test_get_options_returns_expected_structure()
    {
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 10, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();
        $options = $formEngine->getOptions('horoscope.nadi');

        $this->assertCount(1, $options);
        $opt = $options[0];
        $this->assertArrayHasKey('id', $opt);
        $this->assertArrayHasKey('key', $opt);
        $this->assertArrayHasKey('label', $opt);
        $this->assertSame(10, $opt['id']);
        $this->assertSame('adi', $opt['key']);
        $this->assertSame('Adi', $opt['label']);
    }

    public function test_strict_keys_enforced_in_get_options()
    {
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 1, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
            ['id' => 2, 'key' => 'madhya', 'label' => 'Madhya', 'is_active' => true],
            ['id' => 3, 'key' => 'antya', 'label' => 'Antya', 'is_active' => true],
            ['id' => 4, 'key' => 'other', 'label' => 'Other', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();
        $options = $formEngine->getOptions('horoscope.nadi');

        $keys = array_column($options, 'key');
        sort($keys);
        $this->assertSame(['adi', 'antya', 'madhya'], $keys);
        $this->assertFalse(in_array('other', $keys, true));
    }

    public function test_locale_label_uses_app_locale()
    {
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 1, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();

        App::setLocale('en');
        $optionsEn = $formEngine->getOptions('horoscope.nadi');
        $this->assertCount(1, $optionsEn);
        $this->assertSame('Adi', $optionsEn[0]['label']);

        App::setLocale('mr');
        $optionsMr = $formEngine->getOptions('horoscope.nadi');
        $this->assertCount(1, $optionsMr);
        $this->assertNotEmpty($optionsMr[0]['label']);
    }

    public function test_normalize_selected_uses_resolve_id_and_filters_invalid()
    {
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 10, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
            ['id' => 20, 'key' => 'madhya', 'label' => 'Madhya', 'is_active' => false],
            ['id' => 30, 'key' => 'antya', 'label' => 'Antya', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();

        $this->assertSame([10], $formEngine->normalizeSelected('horoscope.nadi', 10));
        $this->assertSame([], $formEngine->normalizeSelected('horoscope.nadi', 20));
        $this->assertSame([], $formEngine->normalizeSelected('horoscope.nadi', 999));
        $this->assertSame([30], $formEngine->normalizeSelected('horoscope.nadi', 30));
    }

    public function test_horoscope_nadi_returns_strict_keys_only()
    {
        // Ensure master_nadis contains at least strict keys + an "other" row.
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 1, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
            ['id' => 2, 'key' => 'madhya', 'label' => 'Madhya', 'is_active' => true],
            ['id' => 3, 'key' => 'antya', 'label' => 'Antya', 'is_active' => true],
            ['id' => 4, 'key' => 'other', 'label' => 'Other', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();
        $meta = $formEngine->build('horoscope.nadi', null);

        $keys = array_map(fn ($opt) => $opt['key'], $meta['options']);
        sort($keys);

        $this->assertSame(['adi', 'antya', 'madhya'], $keys);
        $this->assertFalse(in_array('other', $keys, true));
    }

    public function test_horoscope_gan_returns_strict_keys_only()
    {
        DB::table('master_gans')->truncate();
        DB::table('master_gans')->insert([
            ['id' => 1, 'key' => 'deva', 'label' => 'Deva', 'is_active' => true],
            ['id' => 2, 'key' => 'manav', 'label' => 'Manav', 'is_active' => true],
            ['id' => 3, 'key' => 'rakshasa', 'label' => 'Rakshasa', 'is_active' => true],
            ['id' => 4, 'key' => 'other', 'label' => 'Other', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();
        $meta = $formEngine->build('horoscope.gan', null);

        $keys = array_map(fn ($opt) => $opt['key'], $meta['options']);
        sort($keys);

        $this->assertSame(['deva', 'manav', 'rakshasa'], $keys);
        $this->assertFalse(in_array('other', $keys, true));
    }

    public function test_single_select_marks_selected_option()
    {
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 10, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
            ['id' => 20, 'key' => 'madhya', 'label' => 'Madhya', 'is_active' => true],
            ['id' => 30, 'key' => 'antya', 'label' => 'Antya', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();
        $meta = $formEngine->build('horoscope.nadi', 20);

        $selectedIds = array_values(
            array_map(
                fn ($opt) => $opt['id'],
                array_filter($meta['options'], fn ($opt) => $opt['selected'])
            )
        );

        $this->assertSame([20], $selectedIds);
    }

    public function test_single_select_invalid_selected_is_ignored()
    {
        DB::table('master_nadis')->truncate();
        DB::table('master_nadis')->insert([
            ['id' => 1, 'key' => 'adi', 'label' => 'Adi', 'is_active' => true],
            ['id' => 2, 'key' => 'madhya', 'label' => 'Madhya', 'is_active' => false], // inactive
            ['id' => 3, 'key' => 'antya', 'label' => 'Antya', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();
        $meta = $formEngine->build('horoscope.nadi', 2); // inactive id

        $anySelected = array_filter($meta['options'], fn ($opt) => $opt['selected']);

        $this->assertSame([], array_values($anySelected));
    }

    public function test_multi_select_filters_to_valid_ids_only()
    {
        DB::table('religions')->truncate();
        DB::table('religions')->insert([
            ['id' => 1, 'key' => 'hindu', 'label' => 'Hindu', 'is_active' => true],
            ['id' => 2, 'key' => 'jain', 'label' => 'Jain', 'is_active' => false],
            ['id' => 3, 'key' => 'buddhist', 'label' => 'Buddhist', 'is_active' => true],
        ]);

        $formEngine = $this->makeEngine();
        $meta = $formEngine->build('preference.religion', [1, 2, 999]);

        $selectedIds = array_values(
            array_map(
                fn ($opt) => $opt['id'],
                array_filter($meta['options'], fn ($opt) => $opt['selected'])
            )
        );
        sort($selectedIds);

        $this->assertSame([1], $selectedIds);
    }
}

