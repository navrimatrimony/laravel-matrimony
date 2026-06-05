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
}
