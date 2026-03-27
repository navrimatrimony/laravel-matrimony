<?php

namespace Tests\Unit;

use App\Services\Parsing\ParsedJsonSsotNormalizer;
use PHPUnit\Framework\TestCase;

class ParsedJsonSsotNormalizerTest extends TestCase
{
    public function test_null_string_sentinels_become_real_null(): void
    {
        $in = [
            'core' => [
                'full_name' => '  Rahul  ',
                'caste' => 'null',
                'sub_caste' => 'NIL',
                'father_occupation' => 'n/a',
            ],
            'career_history' => [
                ['job_title' => 'null', 'company' => 'Amdocs', 'location' => '  Pune  '],
            ],
            'education_history' => [
                ['degree' => 'B.E.', 'specialization' => 'null', 'institution' => '—', 'year' => 'null'],
            ],
            'contacts' => [],
            'children' => [],
            'addresses' => [],
            'siblings' => [],
            'relatives' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'preferences' => [],
            'extended_narrative' => [
                'narrative_about_me' => 'null',
                'narrative_expectations' => null,
                'additional_notes' => null,
            ],
            'confidence_map' => ['full_name' => 0.9, 'caste' => 0.8],
        ];

        $out = ParsedJsonSsotNormalizer::normalize($in);

        $this->assertSame('Rahul', $out['core']['full_name']);
        $this->assertNull($out['core']['caste']);
        $this->assertNull($out['core']['sub_caste']);
        $this->assertNull($out['core']['father_occupation']);
        $this->assertNull($out['career_history'][0]['job_title']);
        $this->assertSame('Amdocs', $out['career_history'][0]['company']);
        $this->assertSame('Pune', $out['career_history'][0]['location']);
        $this->assertSame('B.E.', $out['education_history'][0]['degree']);
        $this->assertNull($out['education_history'][0]['specialization']);
        $this->assertNull($out['education_history'][0]['institution']);
        $this->assertNull($out['education_history'][0]['year']);
        $this->assertNull($out['extended_narrative']['narrative_about_me']);
        $this->assertSame(0.9, $out['confidence_map']['full_name']);
        $this->assertLessThanOrEqual(0.15, $out['confidence_map']['caste']);
    }

    public function test_section_label_leakage_nulled_for_horoscope_and_core(): void
    {
        $in = [
            'core' => [
                'full_name' => 'रास',
                'religion' => 'धर्म',
            ],
            'horoscope' => [
                [
                    'rashi' => 'रास',
                    'nakshatra' => 'नक्षत्र',
                    'devak' => 'पंचपल्लव',
                    'kuldaivat' => 'कुलदैवत',
                    'gotra' => 'कश्यप',
                ],
            ],
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'siblings' => [],
            'relatives' => [],
            'property_summary' => [],
            'property_assets' => [],
            'preferences' => [],
            'extended_narrative' => [
                'narrative_about_me' => null,
                'narrative_expectations' => null,
                'additional_notes' => null,
            ],
            'confidence_map' => [],
        ];

        $out = ParsedJsonSsotNormalizer::normalize($in);

        $this->assertNull($out['core']['full_name']);
        $this->assertNull($out['core']['religion']);
        $this->assertNull($out['horoscope'][0]['rashi']);
        $this->assertNull($out['horoscope'][0]['nakshatra']);
        $this->assertNull($out['horoscope'][0]['kuldaivat']);
        $this->assertNotNull($out['horoscope'][0]['gotra']);
    }

    public function test_caste_formatting_noise_stripped(): void
    {
        $in = [
            'core' => [
                'caste' => '% मराठा %',
                'sub_caste' => '**96 कुळी**',
            ],
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'siblings' => [],
            'relatives' => [],
            'horoscope' => [],
            'property_summary' => [],
            'property_assets' => [],
            'preferences' => [],
            'extended_narrative' => [
                'narrative_about_me' => null,
                'narrative_expectations' => null,
                'additional_notes' => null,
            ],
            'confidence_map' => [],
        ];

        $out = ParsedJsonSsotNormalizer::normalize($in);

        $this->assertSame('मराठा', $out['core']['caste']);
        $this->assertStringContainsString('कुळी', (string) $out['core']['sub_caste']);
        $this->assertStringNotContainsString('*', (string) $out['core']['sub_caste']);
    }

    public function test_merge_confidence_maps_preserves_ai_and_takes_max_when_both_present(): void
    {
        $merged = ParsedJsonSsotNormalizer::mergeConfidenceMaps(
            ['full_name' => 0.4, 'caste' => 0.9],
            ['full_name' => 0.85, 'religion' => 0.7]
        );

        $this->assertSame(0.85, $merged['full_name']);
        $this->assertSame(0.9, $merged['caste']);
        $this->assertSame(0.7, $merged['religion']);
    }

    public function test_schema_shape_unchanged_top_level_keys(): void
    {
        $in = [
            'core' => [],
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'siblings' => [],
            'relatives' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'preferences' => [],
            'extended_narrative' => [
                'narrative_about_me' => null,
                'narrative_expectations' => null,
                'additional_notes' => null,
            ],
            'confidence_map' => [],
        ];

        $out = ParsedJsonSsotNormalizer::normalize($in);

        $this->assertSame(array_keys($in), array_keys($out));
    }
}
