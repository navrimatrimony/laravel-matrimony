<?php

namespace Tests\Unit\Parsing;

use App\Services\Parsing\IntakeParsedJsonSectionBuilder;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Tests\TestCase;

class IntakeParsedJsonSectionBuilderTest extends TestCase
{
    public function test_empty_snapshot_produces_all_sections_and_field_keys(): void
    {
        $out = app(IntakeParsedJsonSectionBuilder::class)->build([]);

        $this->assertSame(app(IntakeParsedSnapshotSkeleton::class)->sectionOrder(), $out['section_order']);
        foreach ($out['section_order'] as $section) {
            $this->assertArrayHasKey($section, $out['sectioned']);
        }
        $this->assertArrayHasKey('full_name', $out['sectioned']['basic-info']);
        $this->assertArrayHasKey('height_cm', $out['sectioned']['physical']);
        $this->assertArrayHasKey('highest_education', $out['sectioned']['education-career']);
        $this->assertArrayHasKey('brothers_count', $out['sectioned']['family-details']);
        $this->assertSame([], $out['sectioned']['legal-cases']);
        $this->assertSame([
            'value',
            'raw',
            'source_key',
            'source_section',
            'confidence',
            'status',
            'missing_reason',
        ], array_keys($out['sectioned']['basic-info']['full_name']));
    }

    public function test_filled_missing_and_low_confidence_core_fields_receive_expected_statuses(): void
    {
        $out = app(IntakeParsedJsonSectionBuilder::class)->build([
            'core' => [
                'full_name' => 'Asha Patil',
                'religion' => 'Hindu',
            ],
            'confidence_map' => [
                'core.religion' => 0.60,
            ],
        ]);

        $this->assertSame('filled', $out['sectioned']['basic-info']['full_name']['status']);
        $this->assertSame(0.85, $out['sectioned']['basic-info']['full_name']['confidence']);
        $this->assertSame('missing', $out['sectioned']['basic-info']['sub_caste']['status']);
        $this->assertSame('not_present_in_biodata', $out['sectioned']['basic-info']['sub_caste']['missing_reason']);
        $this->assertSame('low_confidence', $out['sectioned']['basic-info']['religion']['status']);
        $this->assertSame('low_confidence', $out['missing_map']['basic-info.religion']['reason']);
        $this->assertArrayNotHasKey('basic-info.full_name', $out['missing_map']);
    }

    public function test_legacy_sibling_and_relative_rows_are_expanded_to_stable_skeletons(): void
    {
        $out = app(IntakeParsedJsonSectionBuilder::class)->build([
            'siblings' => [
                ['relation_type' => 'brother', 'name' => 'Om Patil'],
            ],
            'relatives' => [
                ['relation_type' => 'maternal_uncle', 'name' => 'Raj Patil'],
            ],
        ]);

        $sibling = $out['sectioned']['siblings'][0];
        foreach (['relation_type', 'name', 'gender', 'marital_status', 'occupation', 'address_line', 'location_display', 'city_id', 'taluka_id', 'district_id', 'state_id', 'contact_number', 'contact_number_2', 'contact_number_3', 'notes', 'sort_order', 'spouse'] as $key) {
            $this->assertArrayHasKey($key, $sibling);
        }
        foreach (['name', 'occupation_title', 'address_line', 'location_display', 'city_id', 'taluka_id', 'district_id', 'state_id', 'contact_number'] as $key) {
            $this->assertArrayHasKey($key, $sibling['spouse']);
        }

        $relative = $out['sectioned']['relatives'][0];
        foreach (['relation_type', 'name', 'occupation', 'marital_status', 'city_id', 'state_id', 'contact_number', 'notes', 'address_line', 'location', 'location_display', 'is_primary_contact', 'raw_note'] as $key) {
            $this->assertArrayHasKey($key, $relative);
        }
    }

    public function test_horoscope_fields_are_routed_to_two_groups(): void
    {
        $out = app(IntakeParsedJsonSectionBuilder::class)->build([
            'horoscope' => [[
                'rashi' => 'Mesha',
                'devak' => 'Peacock feather',
            ]],
        ]);

        $this->assertSame(
            'Peacock feather',
            $out['sectioned']['horoscope']['basic_religious_details']['devak']['value']
        );
        $this->assertSame(
            'Mesha',
            $out['sectioned']['horoscope']['horoscope_details']['rashi']['value']
        );
    }

    public function test_alias_counts_are_derived_without_removing_legacy_keys(): void
    {
        $skeleton = app(IntakeParsedSnapshotSkeleton::class);
        $parsed = $skeleton->ensure([
            'core' => [
                'brother_count' => 2,
                'sister_count' => 1,
            ],
            'legacy_custom_key' => ['kept' => true],
        ]);
        $out = app(IntakeParsedJsonSectionBuilder::class)->build($parsed);

        $this->assertSame(2, $parsed['core']['brothers_count']);
        $this->assertSame(1, $parsed['core']['sisters_count']);
        $this->assertSame(2, $parsed['core']['brother_count']);
        $this->assertSame(1, $parsed['core']['sister_count']);
        $this->assertSame(['kept' => true], $parsed['legacy_custom_key']);
        $this->assertSame(2, $out['sectioned']['family-details']['brothers_count']['value']);
        $this->assertSame([], $parsed['legal_cases']);
    }

    public function test_rejected_review_flag_builds_rejected_field_and_missing_map_entry(): void
    {
        $out = app(IntakeParsedJsonSectionBuilder::class)->build([
            'core' => ['full_name' => 'Section heading'],
        ], [
            'normalized' => [
                'core' => ['full_name' => 'Section heading'],
            ],
            'review_flags' => [[
                'field' => 'core.full_name',
                'reason' => 'candidate_name_from_heading_noise',
            ]],
        ]);

        $field = $out['sectioned']['basic-info']['full_name'];
        $this->assertNull($field['value']);
        $this->assertSame('rejected', $field['status']);
        $this->assertSame('rejected_as_noise', $field['missing_reason']);
        $this->assertSame('rejected_as_noise', $out['missing_map']['basic-info.full_name']['reason']);
    }
}
