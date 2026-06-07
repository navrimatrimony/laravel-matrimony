<?php

namespace Tests\Unit\Intake;

use App\Services\Intake\IntakeNormalizedDraftParsedReconciler;
use Tests\TestCase;

class IntakeNormalizedDraftParsedReconcilerTest extends TestCase
{
    public function test_detects_core_field_present_in_draft_but_missing_in_parsed_json(): void
    {
        $out = app(IntakeNormalizedDraftParsedReconciler::class)->reconcile(
            [
                'core' => [
                    'occupation_title' => 'नोकरी',
                    'company_name' => 'Test Co',
                ],
                'siblings' => [],
                'relatives' => [],
            ],
            [
                'core' => [
                    'company_name' => 'Test Co',
                ],
                'siblings' => [],
                'relatives' => [],
            ]
        );

        $this->assertTrue($out['available']);
        $fields = array_column($out['draft_not_in_parsed'], 'field');
        $this->assertContains('core.occupation_title', $fields);
        $occupation = collect($out['draft_not_in_parsed'])->firstWhere('field', 'core.occupation_title');
        $this->assertSame('नोकरी', $occupation['draft_value'] ?? null);
        $this->assertSame('missing_in_parsed', $occupation['kind'] ?? null);
    }

    public function test_detects_parsed_json_value_missing_from_draft(): void
    {
        $out = app(IntakeNormalizedDraftParsedReconciler::class)->reconcile(
            [
                'core' => [],
                'siblings' => [],
                'relatives' => [],
            ],
            [
                'core' => [
                    'full_name' => 'Test User',
                ],
                'siblings' => [],
                'relatives' => [],
            ]
        );

        $fields = array_column($out['parsed_not_in_draft'], 'field');
        $this->assertContains('core.full_name', $fields);
    }

    public function test_detects_unmatched_sibling_and_relative_rows(): void
    {
        $out = app(IntakeNormalizedDraftParsedReconciler::class)->reconcile(
            [
                'siblings' => [
                    ['relation_type' => 'sister', 'name' => 'अंकिता'],
                ],
                'relatives' => [
                    ['relation_type' => 'paternal_uncle', 'name' => 'मुरलीधर', 'contact_number' => '7709272072'],
                ],
                'core' => [],
            ],
            [
                'siblings' => [],
                'relatives' => [],
                'core' => [],
            ]
        );

        $this->assertNotEmpty($out['draft_not_in_parsed']);
        $this->assertTrue(
            collect($out['draft_not_in_parsed'])->contains(
                fn (array $row): bool => str_starts_with((string) ($row['field'] ?? ''), 'siblings.')
            )
        );
        $this->assertTrue(
            collect($out['draft_not_in_parsed'])->contains(
                fn (array $row): bool => str_starts_with((string) ($row['field'] ?? ''), 'relatives.')
            )
        );
    }

    public function test_unavailable_when_parsed_snapshot_missing(): void
    {
        $out = app(IntakeNormalizedDraftParsedReconciler::class)->reconcile(
            ['core' => ['full_name' => 'Test']],
            null
        );

        $this->assertFalse($out['available']);
        $this->assertSame([], $out['draft_not_in_parsed']);
    }
}
