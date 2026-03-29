<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    private function baseIntakePayload(array $overrides = []): array
    {
        return array_merge([
            'intake_max_daily_per_user' => 5,
            'intake_max_monthly_per_user' => 20,
            'intake_max_pdf_mb' => 10,
            'intake_max_pdf_pages' => 8,
            'intake_max_images_per_intake' => 5,
            'intake_global_daily_cap' => 0,
            'intake_auto_parse_enabled' => 1,
            'intake_ocr_language_hint' => 'mixed',
            'intake_parse_retry_limit' => 3,
            'intake_confidence_high_threshold' => 0.85,
            'intake_file_retention_days' => 90,
        ], $overrides);
    }

    public function test_intake_settings_page_renders_processing_mode_and_end_to_end_provider(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.intake-settings.index'))
            ->assertOk()
            ->assertSee('Processing mode', false)
            ->assertSee('End-to-End', false)
            ->assertSee('Primary AI provider', false);
    }

    public function test_saving_end_to_end_sarvam_syncs_legacy_keys(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.intake-settings.update'), $this->baseIntakePayload([
            'intake_processing_mode' => 'end_to_end',
            'intake_primary_ai_provider' => 'sarvam',
        ]))->assertRedirect(route('admin.intake-settings.index'));

        $this->assertSame('sarvam', AdminSetting::getValue('intake_primary_ai_provider'));
        $this->assertSame('ai_vision_extract_v1', AdminSetting::getValue('intake_active_parser'));
        $this->assertSame('sarvam', AdminSetting::getValue('intake_ai_vision_provider'));
        $this->assertSame('end_to_end', AdminSetting::getValue('intake_processing_mode'));
    }

    public function test_saving_end_to_end_openai_syncs_legacy_keys(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.intake-settings.update'), $this->baseIntakePayload([
            'intake_processing_mode' => 'end_to_end',
            'intake_primary_ai_provider' => 'openai',
        ]))->assertRedirect(route('admin.intake-settings.index'));

        $this->assertSame('openai', AdminSetting::getValue('intake_ai_vision_provider'));
        $this->assertSame('ai_vision_extract_v1', AdminSetting::getValue('intake_active_parser'));
    }

    public function test_saving_hybrid_persists_keys_and_sets_active_parser(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.intake-settings.update'), $this->baseIntakePayload([
            'intake_processing_mode' => 'hybrid',
            'intake_hybrid_extraction_provider' => 'tesseract',
            'intake_hybrid_parser_provider' => 'sarvam',
            'intake_hybrid_ocr_fallback' => 'tesseract',
        ]))->assertRedirect(route('admin.intake-settings.index'));

        $this->assertSame('hybrid_v1', AdminSetting::getValue('intake_active_parser'));
        $this->assertSame('tesseract', AdminSetting::getValue('intake_hybrid_extraction_provider'));
        $this->assertSame('sarvam', AdminSetting::getValue('intake_hybrid_parser_provider'));
        $this->assertSame('tesseract', AdminSetting::getValue('intake_hybrid_ocr_fallback'));
        $this->assertSame('', AdminSetting::getValue('intake_ai_vision_provider'));
        $this->assertSame('tesseract', AdminSetting::getValue('intake_ocr_provider'));
    }
}
