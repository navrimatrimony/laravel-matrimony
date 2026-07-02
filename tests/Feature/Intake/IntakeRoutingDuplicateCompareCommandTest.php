<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command compares eligible duplicate with reference', function () {
    [$current, $reference] = createDuplicateComparePair();

    $exitCode = Artisan::call('intake:routing-duplicate-compare', ['id' => $current->id]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Routing decision summary')
        ->and($output)->toContain('Current intake summary')
        ->and($output)->toContain('Reference intake summary')
        ->and($output)->toContain('Field comparison')
        ->and($output)->toContain('reuse_previous')
        ->and($output)->toContain('duplicate_detected')
        ->and($output)->toContain('duplicate_reuse_eligible')
        ->and($output)->toContain((string) $current->id)
        ->and($output)->toContain((string) $reference->id)
        ->and($output)->toContain('candidate_name')
        ->and($output)->toContain('date_of_birth')
        ->and($output)->toContain('primary_contact')
        ->and($output)->toContain('duplicate_field_match_eligible')
        ->and($output)->toContain('duplicate_field_match_score')
        ->and($output)->toContain('duplicate_field_mismatch_codes')
        ->and($output)->toContain('current_reference_contact_match')
        ->and($output)->toContain('current_reference_dob_match')
        ->and($output)->toContain('current_reference_name_match')
        ->and($output)->toContain('policy_blocked_reason')
        ->and($output)->toContain('routing_disabled');
});

test('command masks contact and does not print raw evidence provider secrets or full address', function () {
    [$current] = createDuplicateComparePair([
        'raw_ocr_text' => 'Sensitive OCR text 9876543210 sk-proj-secret',
        'parsed_json' => duplicateCompareParsed(
            'Current Candidate',
            '1996-04-12',
            '9876543210',
            'MCA',
            '123 Secret Road, Pune'
        ),
    ], [
        'raw_ocr_text' => 'Reference OCR text 9876543210 sk-proj-reference',
        'approval_snapshot_json' => duplicateCompareParsed(
            'Reference Candidate',
            '1996-04-12',
            '9876543210',
            'MCA',
            '123 Secret Road, Pune'
        ),
    ], [
        'signals' => [
            'duplicate_detected' => true,
            'duplicate_reuse_eligible' => true,
            'duplicate_reuse_trust' => 'trusted',
            'duplicate_reference_reason' => 'reference_has_reviewed_snapshot',
            'duplicate_signal_source' => 'content_hash',
            'duplicate_match_type' => 'exact_content_hash',
            'matched_hash_type' => 'content_hash',
            'provider_payload' => 'sk-proj-provider-payload',
        ],
    ]);

    duplicateCompareAttempt($current, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'raw_text' => 'Attempt raw text 9876543210 sk-proj-attempt',
        'engine_meta_json' => ['provider_secret' => 'sk-proj-engine-secret'],
        'is_primary' => true,
    ]);

    $exitCode = Artisan::call('intake:routing-duplicate-compare', ['id' => $current->id]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('******3210')
        ->and($output)->not->toContain('9876543210')
        ->and($output)->not->toContain('Sensitive OCR text')
        ->and($output)->not->toContain('Reference OCR text')
        ->and($output)->not->toContain('Attempt raw text')
        ->and($output)->not->toContain('sk-proj-secret')
        ->and($output)->not->toContain('sk-proj-reference')
        ->and($output)->not->toContain('sk-proj-provider-payload')
        ->and($output)->not->toContain('sk-proj-engine-secret')
        ->and($output)->not->toContain('123 Secret Road');
});

test('missing duplicate reference is handled gracefully', function () {
    $current = createDuplicateCompareIntake([
        'routing_recommendation_json' => duplicateCompareRecommendation(999999),
    ]);

    $exitCode = Artisan::call('intake:routing-duplicate-compare', ['id' => $current->id]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Duplicate reference intake 999999 was not found.')
        ->and($output)->toContain('Routing decision summary')
        ->and($output)->not->toContain('Field comparison');
});

test('intake with no duplicate reference is handled gracefully', function () {
    $current = createDuplicateCompareIntake([
        'routing_recommendation_json' => duplicateCompareRecommendation(null, [
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
            'signals' => [],
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-duplicate-compare', ['id' => $current->id]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No duplicate_reference_intake_id found in routing recommendation signals.')
        ->and($output)->toContain('Routing decision summary')
        ->and($output)->not->toContain('Field comparison');
});

test('json option returns valid json', function () {
    [$current, $reference] = createDuplicateComparePair();

    $payload = duplicateCompareJson($current->id);

    expect($payload['success'])->toBeTrue()
        ->and($payload['can_compare'])->toBeTrue()
        ->and($payload['current_intake']['id'])->toBe($current->id)
        ->and($payload['reference_intake']['id'])->toBe($reference->id)
        ->and($payload['routing_decision']['recommended_action'])->toBe('reuse_previous')
        ->and($payload['routing_decision']['policy_blocked_reason'])->toBe('routing_disabled')
        ->and($payload['routing_decision']['duplicate_field_match_eligible'])->toBe('yes')
        ->and($payload['routing_decision']['duplicate_field_match_score'])->toEqual(1.0)
        ->and($payload['routing_decision']['duplicate_field_mismatch_codes'])->toBe([])
        ->and($payload['routing_decision']['current_reference_contact_match'])->toBe('yes')
        ->and($payload['routing_decision']['current_reference_dob_match'])->toBe('yes')
        ->and($payload['routing_decision']['current_reference_name_match'])->toBe('yes')
        ->and($payload['field_comparison'])->not->toBeEmpty()
        ->and($payload['field_comparison'][0])->toHaveKeys(['group', 'field', 'current', 'reference', 'match']);
});

test('command does not mutate parsed raw parse status routing json ocr attempts or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Compare',
    ]);
    [$current] = createDuplicateComparePair([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text 9876543210',
        'parse_status' => 'parsed',
    ]);
    $parsedBefore = $current->parsed_json;
    $routingBefore = $current->routing_recommendation_json;
    $rawBefore = $current->raw_ocr_text;
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $current->id)->count();

    $payload = duplicateCompareJson($current->id);

    $current->refresh();
    $profile->refresh();

    expect($payload['success'])->toBeTrue()
        ->and($current->parsed_json)->toBe($parsedBefore)
        ->and($current->raw_ocr_text)->toBe($rawBefore)
        ->and($current->parse_status)->toBe('parsed')
        ->and($current->routing_recommendation_json)->toBe($routingBefore)
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $current->id)->count())->toBe($attemptCountBefore)
        ->and($profile->full_name)->toBe('Profile Before Compare');
});

function duplicateCompareJson(int $id, array $parameters = []): array
{
    $exitCode = Artisan::call('intake:routing-duplicate-compare', array_merge(['id' => $id, '--json' => true], $parameters));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createDuplicateComparePair(array $currentOverrides = [], array $referenceOverrides = [], array $recommendationOverrides = []): array
{
    $reference = createDuplicateCompareIntake(array_merge([
        'parsed_json' => duplicateCompareParsed('Duplicate Candidate', '1996-04-12', '9876543210', 'MCA'),
        'approval_snapshot_json' => duplicateCompareParsed('Duplicate Candidate', '1996-04-12', '9876543210', 'MCA'),
        'approved_by_user' => true,
        'approved_at' => now(),
        'reviewed_by_user_id' => User::factory()->create()->id,
        'review_actor_type' => 'admin',
        'review_surface' => 'admin_panel',
        'reviewed_at' => now(),
        'intake_locked' => true,
        'content_hash' => 'same-content-hash',
        'quality_summary_json' => ['score' => 0.94],
    ], $referenceOverrides));
    duplicateCompareAttempt($reference, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'quality_score' => 0.94,
        'normalized_text_hash' => 'reference-normalized-hash',
        'is_primary' => true,
    ]);

    $recommendation = duplicateCompareRecommendation($reference->id, $recommendationOverrides);
    $current = createDuplicateCompareIntake(array_merge([
        'parsed_json' => duplicateCompareParsed('Duplicate Candidate', '1996-04-12', '9876543210', 'MCA'),
        'content_hash' => 'same-content-hash',
        'quality_summary_json' => ['score' => 0.91],
        'routing_recommendation_json' => $recommendation,
        'routing_telemetry_json' => [
            'mode' => 'dry_run',
            'sarvam_attempt_count' => 0,
            'cheap_ocr_attempt_count' => 1,
            'failed_provider_count' => 0,
            'reuse_candidate_found' => true,
            'last_quality_score' => 0.91,
        ],
    ], $currentOverrides));
    duplicateCompareAttempt($current, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'quality_score' => 0.91,
        'image_hash' => 'current-image-hash',
        'is_primary' => true,
    ]);

    return [$current, $reference];
}

function createDuplicateCompareIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => duplicateCompareParsed('Parsed Candidate', '1996-04-12', '9876543210', 'MCA'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
        'routing_recommendation_json' => null,
        'routing_telemetry_json' => null,
    ], $overrides));
}

function duplicateCompareParsed(
    string $name,
    string $dateOfBirth,
    string $phone,
    string $education,
    string $address = 'Pune'
): array {
    return [
        'core' => [
            'full_name' => $name,
            'date_of_birth' => $dateOfBirth,
            'primary_contact_number' => $phone,
            'highest_education' => $education,
            'current_address' => $address,
        ],
        'contacts' => [
            [
                'phone_number' => $phone,
                'is_primary' => true,
                'contact_name' => 'Self',
            ],
        ],
        'education_history' => [
            [
                'degree' => $education,
            ],
        ],
        'addresses' => [
            [
                'type' => 'residence',
                'address' => $address,
            ],
        ],
    ];
}

function duplicateCompareRecommendation(?int $referenceId, array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'dry_run',
        'recommended_action' => 'reuse_previous',
        'reason_codes' => ['duplicate_detected', 'duplicate_reuse_eligible'],
        'confidence' => 0.95,
        'would_skip_paid_vision' => true,
        'would_call_paid_vision' => false,
        'signals' => $referenceId !== null ? [
            'duplicate_detected' => true,
            'duplicate_reuse_eligible' => true,
            'duplicate_reuse_trust' => 'trusted',
            'duplicate_reference_intake_id' => $referenceId,
            'duplicate_reference_reason' => 'reference_has_reviewed_snapshot',
            'duplicate_signal_source' => 'content_hash',
            'duplicate_match_type' => 'exact_content_hash',
            'matched_hash_type' => 'content_hash',
            'duplicate_reference_has_reviewed_snapshot' => true,
            'duplicate_field_match_eligible' => true,
            'duplicate_field_match_score' => 1.0,
            'duplicate_field_mismatch_codes' => [],
            'current_reference_contact_match' => 'yes',
            'current_reference_dob_match' => 'yes',
            'current_reference_name_match' => 'yes',
            'current_reference_core_fields_compared' => 3,
        ] : [],
    ], $overrides);
}

function duplicateCompareAttempt(BiodataIntake $intake, array $overrides = []): BiodataIntakeOcrAttempt
{
    return BiodataIntakeOcrAttempt::create(array_merge([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Name: Candidate',
        'quality_score' => 0.9,
        'cost_units' => 0,
        'is_primary' => false,
    ], $overrides));
}
