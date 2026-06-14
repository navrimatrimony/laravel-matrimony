<?php

namespace Tests\Feature\Intake;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParseIntakeJobFailureStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_marks_pending_intake_as_error_with_last_error(): void
    {
        $owner = User::factory()->create();
        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $owner->id,
            'raw_ocr_text' => 'sample',
            'parse_status' => 'pending',
            'intake_status' => 'uploaded',
            'approved_by_user' => false,
            'intake_locked' => false,
        ]);

        $job = new ParseIntakeJob((int) $intake->id, true);
        $job->failed(new \RuntimeException('queue worker timeout while reparsing'));

        $intake->refresh();

        $this->assertSame('error', $intake->parse_status);
        $this->assertSame('queue worker timeout while reparsing', $intake->last_error);
    }

    public function test_ai_vision_mode_parses_raw_text_only_intake_without_readable_file(): void
    {
        config([
            'intake.testing_active_parser' => 'rules_only',
            'intake.testing_parse_job_uses_ai_vision' => true,
        ]);

        $owner = User::factory()->create();
        $rawText = implode("\n", [
            'Name: Aparna QA Candidate',
            'Gender: Female',
            'Date of Birth: 09-12-2000',
            'Birth Place: Pune',
            'Religion: Hindu',
            'Caste: Maratha',
            'Education: B.Com',
            'Occupation: Accountant',
            'Mobile: 9612345678',
            'Address: Pune, Maharashtra',
        ]);

        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $owner->id,
            'file_path' => null,
            'original_filename' => null,
            'raw_ocr_text' => $rawText,
            'parse_status' => 'pending',
            'intake_status' => 'uploaded',
            'approved_by_user' => false,
            'intake_locked' => false,
            'parser_version' => 'rules_only',
            'snapshot_schema_version' => 1,
        ]);

        (new ParseIntakeJob((int) $intake->id))->handle();

        $intake->refresh();

        $this->assertSame('parsed', $intake->parse_status);
        $this->assertNotSame('no_readable_source_file', $intake->last_error);
        $this->assertStringContainsString('aparna qa candidate', (string) $intake->last_parse_input_text);
    }
}
