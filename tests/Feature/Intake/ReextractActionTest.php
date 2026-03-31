<?php

namespace Tests\Feature\Intake;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReextractActionTest extends TestCase
{
    use RefreshDatabase;

    private function longMarathiBiodataText(): string
    {
        return str_repeat(
            "मुलीचे नांव : कु. टेस्ट परसे\nजन्मतारीख : 12/03/1996\nमो 9876543210\nशिक्षण बी.कॉम\nनोकरी खाजगी\nजन्म\nनाव\nधर्म हिंदू\n",
            8
        );
    }

    public function test_admin_re_extract_triggers_paid_vision_extraction_once(): void
    {
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $owner->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->partialMock(AiVisionExtractionService::class, function ($m) use ($text) {
            $m->shouldReceive('extractTextForIntake')->once()->andReturn([
                'text' => $text,
                'meta' => [
                    'ok' => true,
                    'provider' => 'openai',
                    'extraction' => 'test',
                ],
            ]);
        });

        $this->actingAs($admin)
            ->post(route('admin.biodata-intakes.re-extract', $intake))
            ->assertRedirect(route('admin.biodata-intakes.show', $intake));

        $intake->refresh();
        $this->assertSame('parsed', $intake->parse_status);

        $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
        $this->assertIsArray($dbg);
        $this->assertSame('ai_vision_extract_v1', $dbg['parse_input_source'] ?? null);
        $this->assertNotSame('raw_ocr_text_column', $dbg['parse_input_source'] ?? null);
        $this->assertTrue($dbg['ok'] ?? false, 'preview debug should show AI extract ok when extraction succeeds');
        $this->assertSame('openai', $dbg['provider'] ?? null);
    }

    public function test_owner_can_post_re_extract_when_vision_mode_enabled(): void
    {
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $owner = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $owner->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->partialMock(AiVisionExtractionService::class, function ($m) use ($text) {
            $m->shouldReceive('extractTextForIntake')->once()->andReturn([
                'text' => $text,
                'meta' => [
                    'ok' => true,
                    'provider' => 'openai',
                    'extraction' => 'test',
                ],
            ]);
        });

        $this->actingAs($owner)
            ->post(route('intake.re-extract', $intake))
            ->assertRedirect(route('intake.index'));

        $intake->refresh();
        $this->assertSame('parsed', $intake->parse_status);

        $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
        $this->assertIsArray($dbg);
        $this->assertSame('ai_vision_extract_v1', $dbg['parse_input_source'] ?? null);
        $this->assertTrue($dbg['ok'] ?? false);
    }

    public function test_re_extract_uses_vision_path_and_shows_sarvam_in_debug_when_selected(): void
    {
        // Resolve parser mode from AdminSetting like production (not rules_only test override).
        config(['intake.testing_active_parser' => null]);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        AdminSetting::setValue('intake_processing_mode', 'end_to_end');
        AdminSetting::setValue('intake_active_parser', 'ai_vision_extract_v1');
        AdminSetting::setValue('intake_primary_ai_provider', 'sarvam');
        AdminSetting::setValue('intake_ai_vision_provider', 'sarvam');

        $owner = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $owner->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->partialMock(AiVisionExtractionService::class, function ($m) use ($text) {
            $m->shouldReceive('extractTextForIntake')->once()->andReturn([
                'text' => $text,
                'meta' => [
                    'ok' => true,
                    'provider' => 'sarvam',
                    'provider_source' => 'admin_setting',
                    'extraction' => 'test',
                ],
            ]);
        });

        $this->actingAs($owner)
            ->post(route('intake.re-extract', $intake))
            ->assertRedirect(route('intake.index'));

        $intake->refresh();
        $this->assertSame('parsed', $intake->parse_status);

        $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
        $this->assertIsArray($dbg);
        $this->assertSame('ai_vision_extract_v1', $dbg['parse_input_source'] ?? null);
        $this->assertSame('sarvam', $dbg['provider'] ?? null);
        $this->assertTrue($dbg['ok'] ?? false);
    }
}
