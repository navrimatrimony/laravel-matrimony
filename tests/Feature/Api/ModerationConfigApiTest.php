<?php

namespace Tests\Feature\Api;

use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationConfigApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderation_config_returns_defaults_when_settings_empty(): void
    {
        $response = $this->getJson('/api/moderation-config');

        $response->assertOk();
        $response->assertJson([
            'nsfw_score_min' => 0.4,
            'review_score_min' => 0.53,
            'ignore_classes' => ['FACE_FEMALE', 'FACE_MALE'],
        ]);
        $response->assertJsonStructure(['version']);
        $this->assertMatchesRegularExpression('/^v1-[a-f0-9]{12}$/', (string) $response->json('version'));
    }

    public function test_moderation_config_reflects_admin_settings(): void
    {
        AdminSetting::setValue('moderation_nsfw_score_min', '0.55');
        AdminSetting::setValue('moderation_review_score_min', '0.61');
        AdminSetting::setValue('moderation_ignore_classes', json_encode(['ARMPITS_EXPOSED']));

        $response = $this->getJson('/api/moderation-config');

        $response->assertOk();
        $response->assertJson([
            'nsfw_score_min' => 0.55,
            'review_score_min' => 0.61,
            'ignore_classes' => ['ARMPITS_EXPOSED'],
        ]);
    }
}
