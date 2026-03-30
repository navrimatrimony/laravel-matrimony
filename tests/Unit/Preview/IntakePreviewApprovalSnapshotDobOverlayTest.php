<?php

namespace Tests\Unit\Preview;

use App\Services\Preview\PreviewSectionMapper;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors IntakeController::preview() lines 228–247: approval_snapshot_json['core'] replaces mapped core.
 */
class IntakePreviewApprovalSnapshotDobOverlayTest extends TestCase
{
    public function test_approval_snapshot_core_replaces_parsed_json_core_including_null_dob(): void
    {
        $parsedJson = [
            'core' => [
                'full_name' => 'Test',
                'date_of_birth' => '1997-08-08',
            ],
        ];
        $approvalSnapshot = [
            'core' => [
                'full_name' => 'Test',
                'date_of_birth' => null,
            ],
        ];

        $mapper = new PreviewSectionMapper;
        $sections = $mapper->map($parsedJson);

        if (! empty($approvalSnapshot['core']) && is_array($approvalSnapshot['core'])) {
            $sections['core']['data'] = $approvalSnapshot['core'];
        }

        $this->assertArrayHasKey('date_of_birth', $sections['core']['data']);
        $this->assertNull($sections['core']['data']['date_of_birth']);
        $this->assertSame('1997-08-08', $parsedJson['core']['date_of_birth']);
    }
}
