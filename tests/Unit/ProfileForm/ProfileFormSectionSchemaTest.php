<?php

namespace Tests\Unit\ProfileForm;

use App\Services\ProfileForm\ProfileFormSectionSchema;
use Tests\TestCase;

class ProfileFormSectionSchemaTest extends TestCase
{
    public function test_canonical_section_order_matches_current_wizard_order(): void
    {
        $this->assertSame(
            config('field_catalog.section_order'),
            ProfileFormSectionSchema::orderedKeys()
        );
    }

    public function test_schema_contains_current_shared_editable_sections(): void
    {
        $expected = [
            'basic-info',
            'physical',
            'education-career',
            'family-details',
            'siblings',
            'relatives',
            'alliance',
            'property',
            'horoscope',
            'about-me',
            'about-preferences',
        ];

        $this->assertSame($expected, ProfileFormSectionSchema::fullFormSectionKeys());

        foreach ($expected as $position => $key) {
            $section = ProfileFormSectionSchema::forKey($key);

            $this->assertNotNull($section);
            $this->assertTrue($section['editable']);
            $this->assertTrue($section['in_full_form']);
            $this->assertSame('shared', $section['surface']);
            $this->assertSame($position + 1, $section['display_order']);
            $this->assertSame(
                'matrimony.profile.wizard.sections.'.str_replace('-', '_', $key),
                $section['partial']
            );
        }
    }

    public function test_full_form_sections_helper_returns_only_canonical_editable_sections_in_order(): void
    {
        $sections = ProfileFormSectionSchema::fullFormSections();

        $this->assertSame(
            ProfileFormSectionSchema::fullFormSectionKeys(),
            array_column($sections, 'key')
        );
        $this->assertSame(
            ProfileFormSectionSchema::fullFormSectionKeys(),
            array_column(array_filter($sections, static fn (array $section): bool => $section['editable']), 'key')
        );
    }

    public function test_photo_is_a_wizard_section_but_not_in_current_full_editable_form(): void
    {
        $photo = ProfileFormSectionSchema::forKey('photo');

        $this->assertNotNull($photo);
        $this->assertFalse($photo['editable']);
        $this->assertFalse($photo['in_full_form']);
        $this->assertSame('wizard-only', $photo['surface']);
        $this->assertNull($photo['partial']);
    }

    public function test_schema_does_not_mix_in_intake_only_audit_panels(): void
    {
        $keys = ProfileFormSectionSchema::orderedKeys();

        $this->assertNotContains('review_needed', $keys);
        $this->assertNotContains('parse_input_text', $keys);
        $this->assertNotContains('parsed_json', $keys);
    }

    public function test_full_form_blade_includes_match_schema_full_form_order(): void
    {
        $path = resource_path('views/matrimony/profile/wizard/sections/full_form.blade.php');
        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        preg_match_all(
            "/@include\\('matrimony\\.profile\\.wizard\\.sections\\.([^']+)'/",
            $contents,
            $matches
        );

        $includedSectionKeys = array_map(
            static fn (string $partial): string => str_replace('_', '-', $partial),
            $matches[1] ?? []
        );

        $this->assertSame(
            ProfileFormSectionSchema::fullFormSectionKeys(),
            $includedSectionKeys
        );
    }
}
