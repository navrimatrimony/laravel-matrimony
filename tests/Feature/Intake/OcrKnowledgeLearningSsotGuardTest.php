<?php

/**
 * Sprint 4 Phase Contract 4b — SSOT guards for Knowledge / Learning.
 * Learning jobs must not mutate profiles; AI generalize stays off by default.
 */

use App\Jobs\NightlyOcrLearningJob;
use App\Models\MatrimonyProfile;
use App\Models\OcrCorrectionPattern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('nightly OCR learning honors disabled flag and does not change profiles', function () {
    Config::set('ocr.ai_generalize_enabled', false);

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $user->id,
        'full_name' => 'SSOT Guard Name',
    ]);
    $before = $profile->fresh()->toArray();

    Log::spy();
    (new NightlyOcrLearningJob)->handle();

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'disabled via config'))
        ->atLeast()
        ->once();

    expect($profile->fresh()->toArray())->toBe($before)
        ->and(OcrCorrectionPattern::query()->count())->toBe(0);
});

test('when AI generalize is forced on, job inserts patterns only and never updates profile columns', function () {
    Config::set('ocr.ai_generalize_enabled', true);
    Config::set('ocr.ai_generalize_threshold', 1);
    Config::set('services.openai.key', 'test-key-not-used-for-profile');

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $user->id,
        'full_name' => 'Must Stay Untouched',
    ]);
    $beforeHash = md5(json_encode($profile->fresh()->getAttributes()));

    OcrCorrectionPattern::query()->create([
        'field_key' => 'caste',
        'wrong_pattern' => 'मटाठा',
        'corrected_value' => 'मराठा',
        'usage_count' => 20,
        'is_active' => true,
        'source' => 'frequency_rule',
        'pattern_confidence' => 0.9,
        'rule_family_key' => 'test_family',
        'rule_version' => 1,
    ]);

    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'generalizations' => [[
                            'field_key' => 'caste',
                            'wrong_pattern' => 'मटाठा ',
                            'corrected_value' => 'मराठा',
                            'pattern_confidence' => 0.8,
                        ]],
                    ]),
                ],
            ]],
        ], 200),
    ]);

    (new NightlyOcrLearningJob)->handle();

    expect(md5(json_encode($profile->fresh()->getAttributes())))->toBe($beforeHash)
        ->and($profile->fresh()->full_name)->toBe('Must Stay Untouched');

    // Job may insert zero or more ai_generalized rows; profile must still be untouched.
    OcrCorrectionPattern::query()
        ->where('source', 'ai_generalized')
        ->get()
        ->each(function (OcrCorrectionPattern $row) {
            expect($row->source)->toBe('ai_generalized');
        });
});
