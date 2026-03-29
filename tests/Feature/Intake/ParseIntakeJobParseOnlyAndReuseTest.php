<?php

namespace Tests\Feature\Intake;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ParseIntakeJobParseOnlyAndReuseTest extends TestCase
{
    use RefreshDatabase;

    private function longMarathiBiodataText(): string
    {
        return str_repeat(
            "मुलीचे नांव : कु. टेस्ट परसे\nजन्मतारीख : 12/03/1996\nमो 9876543210\nशिक्षण बी.कॉम\nनोकरी खाजगी\nजन्म\nनाव\nधर्म हिंदू\n",
            8
        );
    }

    public function test_parse_input_only_job_does_not_call_paid_vision_extraction(): void
    {
        Cache::flush();
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $user = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        Cache::put('intake.parse_input_text.'.$intake->id, $text, 3600);

        $this->partialMock(AiVisionExtractionService::class, function ($m) {
            $m->shouldNotReceive('extractTextForIntake');
        });

        IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);

        (new ParseIntakeJob((int) $intake->id, true))->handle();

        $intake->refresh();
        $this->assertSame('parsed', $intake->parse_status);
    }

    public function test_fingerprint_reuse_skips_paid_extraction_for_second_intake(): void
    {
        Cache::flush();
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $user = User::factory()->create();
        $identity = $this->longMarathiBiodataText();

        $intake1 = BiodataIntake::create([
            'raw_ocr_text' => $identity,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        app(IntakeExtractionReuseResolver::class)->recordSuccessfulPaidExtraction(
            $intake1,
            'openai',
            $identity,
            0.92,
        );

        $intake2 = BiodataIntake::create([
            'raw_ocr_text' => $identity,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->partialMock(AiVisionExtractionService::class, function ($m) {
            $m->shouldNotReceive('extractTextForIntake');
        });

        (new ParseIntakeJob((int) $intake2->id, false))->handle();

        $intake2->refresh();
        $this->assertSame('parsed', $intake2->parse_status);
    }

    public function test_historical_peer_raw_ocr_reuse_when_cache_cleared_and_no_fingerprint_entry(): void
    {
        Cache::flush();
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $user = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $intake2 = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        Cache::flush();

        $this->partialMock(AiVisionExtractionService::class, function ($m) {
            $m->shouldNotReceive('extractTextForIntake');
        });

        (new ParseIntakeJob((int) $intake2->id, false))->handle();

        $intake2->refresh();
        $this->assertSame('parsed', $intake2->parse_status);
    }

    public function test_mismatched_identity_does_not_reuse_historical_and_calls_paid_extraction(): void
    {
        Cache::flush();
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $user = User::factory()->create();
        $textA = $this->longMarathiBiodataText();
        $textB = str_replace('9876543210', '9876543211', $textA);

        BiodataIntake::create([
            'raw_ocr_text' => $textA,
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $intake2 = BiodataIntake::create([
            'raw_ocr_text' => $textB,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        Cache::flush();

        $this->partialMock(AiVisionExtractionService::class, function ($m) use ($textB) {
            $m->shouldReceive('extractTextForIntake')->once()->andReturn([
                'text' => $textB,
                'meta' => [
                    'ok' => true,
                    'provider' => 'openai',
                    'extraction' => 'test',
                ],
            ]);
        });

        (new ParseIntakeJob((int) $intake2->id, false))->handle();

        $intake2->refresh();
        $this->assertSame('parsed', $intake2->parse_status);
    }
}
