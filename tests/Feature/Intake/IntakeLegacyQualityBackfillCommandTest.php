<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('command backfills quality and field confidence from stored parsed json and raw ocr text', function () {
    $parsed = legacyQualityParsedJson();
    $intake = createLegacyQualityBackfillIntake([
        'raw_ocr_text' => legacyQualityRawText(),
        'parsed_json' => $parsed,
        'quality_summary_json' => null,
        'failure_codes_json' => null,
        'field_confidence_json' => null,
    ]);

    $this->artisan('intake:legacy-quality-backfill', ['--id' => $intake->id])
        ->assertExitCode(0);

    $stored = $intake->refresh();

    expect($stored->quality_summary_json)->not->toBeNull()
        ->and($stored->quality_summary_json['line_count'])->toBe(6)
        ->and($stored->failure_codes_json)->toBe([])
        ->and($stored->field_confidence_json['full_name']['present'])->toBeTrue()
        ->and($stored->field_confidence_json['primary_contact_number']['present'])->toBeTrue()
        ->and($stored->field_confidence_json['religion']['present'])->toBeTrue();
});

test('dry run computes signals without saving', function () {
    $intake = createLegacyQualityBackfillIntake([
        'raw_ocr_text' => legacyQualityRawText(),
        'parsed_json' => legacyQualityParsedJson(),
        'quality_summary_json' => null,
        'failure_codes_json' => null,
        'field_confidence_json' => null,
    ]);

    $this->artisan('intake:legacy-quality-backfill', [
        '--id' => $intake->id,
        '--dry-run' => true,
    ])->assertExitCode(0);

    $stored = $intake->refresh();

    expect($stored->quality_summary_json)->toBeNull()
        ->and($stored->failure_codes_json)->toBeNull()
        ->and($stored->field_confidence_json)->toBeNull();
});

test('all option refreshes existing quality fields', function () {
    $intake = createLegacyQualityBackfillIntake([
        'raw_ocr_text' => legacyQualityRawText(),
        'parsed_json' => legacyQualityParsedJson(),
        'quality_summary_json' => [
            'score' => 0.1,
            'is_low' => true,
            'line_count' => 1,
        ],
        'failure_codes_json' => [
            BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT,
        ],
        'field_confidence_json' => [
            'full_name' => [
                'score' => 0.1,
                'present' => false,
                'source_path' => null,
                'reason' => 'legacy_placeholder',
            ],
        ],
    ]);

    $this->artisan('intake:legacy-quality-backfill', [
        '--id' => $intake->id,
        '--all' => true,
    ])->assertExitCode(0);

    $stored = $intake->refresh();

    expect($stored->quality_summary_json['score'])->not->toBe(0.1)
        ->and($stored->quality_summary_json['line_count'])->toBe(6)
        ->and($stored->failure_codes_json)->toBe([])
        ->and($stored->field_confidence_json['full_name']['present'])->toBeTrue()
        ->and($stored->field_confidence_json['full_name']['reason'])->toBe('parsed_value_present');
});

test('command preserves parsed json raw ocr text parse status ocr attempts and profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Legacy Backfill',
    ]);
    $parsed = legacyQualityParsedJson();
    $rawText = legacyQualityRawText();
    $intake = createLegacyQualityBackfillIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => $rawText,
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => null,
        'failure_codes_json' => null,
        'field_confidence_json' => null,
    ]);
    DB::table('biodata_intakes')
        ->where('id', $intake->id)
        ->update(['updated_at' => now()->subDay()]);
    $updatedAtBefore = $intake->refresh()->updated_at?->toDateTimeString();
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $rawText,
        'quality_score' => 0.9,
        'cost_units' => 0,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $this->artisan('intake:legacy-quality-backfill', ['--id' => $intake->id])
        ->assertExitCode(0);

    $intake->refresh();
    $profile->refresh();

    expect($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe($rawText)
        ->and($intake->parse_status)->toBe('parsed')
        ->and($intake->updated_at?->toDateTimeString())->toBe($updatedAtBefore)
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($profile->full_name)->toBe('Profile Before Legacy Backfill');
});

test('locked intake is reported as skipped locked without saving', function () {
    $intake = createLegacyQualityBackfillIntake([
        'raw_ocr_text' => legacyQualityRawText(),
        'parsed_json' => legacyQualityParsedJson(),
        'intake_locked' => true,
        'quality_summary_json' => null,
        'failure_codes_json' => null,
        'field_confidence_json' => null,
    ]);

    $exitCode = Artisan::call('intake:legacy-quality-backfill', [
        '--id' => $intake->id,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload['skipped_locked'])->toBe(1)
        ->and($payload['rows'][0]['status'])->toBe('skipped_locked')
        ->and($intake->refresh()->quality_summary_json)->toBeNull()
        ->and($intake->field_confidence_json)->toBeNull();
});

test('missing parsed and raw stored data is handled gracefully', function () {
    $intake = createLegacyQualityBackfillIntake([
        'raw_ocr_text' => '',
        'parsed_json' => null,
        'quality_summary_json' => null,
        'failure_codes_json' => null,
        'field_confidence_json' => null,
    ]);

    $this->artisan('intake:legacy-quality-backfill', ['--id' => $intake->id])
        ->assertExitCode(0);

    $stored = $intake->refresh();

    expect((float) $stored->quality_summary_json['score'])->toBe(0.0)
        ->and($stored->quality_summary_json['is_low'])->toBeTrue()
        ->and($stored->failure_codes_json)->toContain(BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT)
        ->and($stored->field_confidence_json)->toBeNull()
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe(0);
});

function createLegacyQualityBackfillIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => legacyQualityRawText(),
        'parsed_json' => legacyQualityParsedJson(),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
        'quality_summary_json' => null,
        'failure_codes_json' => null,
        'field_confidence_json' => null,
    ], $overrides));
}

function legacyQualityRawText(): string
{
    return implode("\n", [
        'नाव : राहुल पाटील',
        'जन्म तारीख : 12/04/1996',
        'शिक्षण : B.Com',
        'नोकरी : Software Developer',
        'मोबाईल : 9876543210',
        'पत्ता : पुणे',
    ]);
}

/**
 * @return array<string, mixed>
 */
function legacyQualityParsedJson(): array
{
    return [
        'core' => [
            'full_name' => 'राहुल पाटील',
            'date_of_birth' => '1996-04-12',
            'height_cm' => 172,
            'highest_education' => 'B.Com',
            'occupation_title' => 'Software Developer',
            'religion_id' => 1,
            'caste_id' => 2,
        ],
        'contacts' => [
            ['phone_number' => '9876543210'],
        ],
        'addresses' => [
            ['address_line' => 'Pune'],
        ],
    ];
}
