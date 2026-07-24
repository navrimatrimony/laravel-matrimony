<?php

namespace Tests\Unit;

use App\Support\LocalizedText;
use Tests\TestCase;

/**
 * Master data is translated column by column, so at any moment some rows have
 * Marathi and some do not. These tests pin the rule that lets that be true
 * without anyone seeing a blank label: Marathi when it is really there,
 * English otherwise, and Marathi appears the moment the column is filled — no
 * deploy, no config, no cache clear.
 *
 * The whitespace cases are not hypothetical. Three models used to guard with
 * `!== ''` and no trim, so a Marathi value of "   " passed as present and
 * rendered as an empty label in every Marathi screen.
 */
class LocalizedTextTest extends TestCase
{
    private function marathi(): void
    {
        app()->setLocale('mr');
    }

    private function english(): void
    {
        app()->setLocale('en');
    }

    public function test_marathi_locale_shows_marathi_when_it_is_present(): void
    {
        $this->marathi();

        $this->assertSame('पुणे', LocalizedText::pick('पुणे', 'Pune'));
    }

    public function test_marathi_locale_falls_back_to_english_when_marathi_is_null(): void
    {
        $this->marathi();

        $this->assertSame('Pune', LocalizedText::pick(null, 'Pune'));
    }

    public function test_marathi_locale_falls_back_to_english_when_marathi_is_an_empty_string(): void
    {
        $this->marathi();

        $this->assertSame('Pune', LocalizedText::pick('', 'Pune'));
    }

    public function test_marathi_locale_falls_back_to_english_when_marathi_is_only_whitespace(): void
    {
        $this->marathi();

        // The defect this whole change exists to remove.
        $this->assertSame('Pune', LocalizedText::pick('   ', 'Pune'));
    }

    public function test_english_locale_never_shows_marathi_even_when_it_is_present(): void
    {
        $this->english();

        $this->assertSame('Pune', LocalizedText::pick('पुणे', 'Pune'));
    }

    public function test_returned_values_are_trimmed_in_both_locales(): void
    {
        $this->marathi();
        $this->assertSame('पुणे', LocalizedText::pick('  पुणे  ', 'Pune'));

        $this->english();
        $this->assertSame('Pune', LocalizedText::pick('पुणे', '  Pune  '));
    }

    public function test_column_derives_the_marathi_column_from_the_base_name(): void
    {
        $this->marathi();

        $this->assertSame('पुणे', LocalizedText::column(['name' => 'Pune', 'name_mr' => 'पुणे'], 'name'));
    }

    public function test_column_walks_the_english_chain_in_order(): void
    {
        $this->english();

        $row = ['label' => 'fallback', 'label_en' => 'preferred'];
        $this->assertSame('preferred', LocalizedText::column($row, 'label', ['label_en', 'label']));

        $row = ['label' => 'fallback', 'label_en' => ''];
        $this->assertSame('fallback', LocalizedText::column($row, 'label', ['label_en', 'label']));
    }

    public function test_column_returns_an_empty_string_for_a_null_row_and_does_not_throw(): void
    {
        $this->marathi();

        $this->assertSame('', LocalizedText::column(null, 'name'));
    }

    public function test_column_reads_arrays_and_query_builder_rows_identically(): void
    {
        $this->marathi();

        $array = ['name' => 'Pune', 'name_mr' => 'पुणे'];
        $object = (object) $array;

        $this->assertSame(
            LocalizedText::column($array, 'name'),
            LocalizedText::column($object, 'name'),
        );
        $this->assertSame('पुणे', LocalizedText::column($object, 'name'));
    }

    public function test_column_handles_a_non_standard_marathi_column_prefix(): void
    {
        $this->marathi();

        $row = ['display_label' => 'Height', 'display_label_mr' => 'उंची'];
        $this->assertSame('उंची', LocalizedText::column($row, 'display_label'));
    }

    public function test_column_returns_empty_when_neither_language_has_a_value(): void
    {
        $this->marathi();

        $this->assertSame('', LocalizedText::column(['name' => '  ', 'name_mr' => null], 'name'));
    }

    /**
     * The behaviour the whole feature is for: translating a row later must take
     * effect on the next read, with nothing else changed.
     */
    public function test_filling_the_marathi_column_later_switches_the_output_with_no_other_change(): void
    {
        $this->marathi();

        $row = ['name' => 'Pune', 'name_mr' => null];
        $this->assertSame('Pune', LocalizedText::column($row, 'name'));

        $row['name_mr'] = 'पुणे';
        $this->assertSame('पुणे', LocalizedText::column($row, 'name'));
    }

    public function test_an_unsupported_locale_is_not_treated_as_marathi(): void
    {
        app()->setLocale('hi');

        // 'hi' is not "not English" — it is its own language, with no column.
        $this->assertFalse(LocalizedText::isMarathi());
        $this->assertSame('Pune', LocalizedText::pick('पुणे', 'Pune'));
    }
}
