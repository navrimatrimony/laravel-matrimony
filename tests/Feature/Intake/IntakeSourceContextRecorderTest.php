<?php

use App\Models\BiodataIntake;
use App\Models\IntakeSourceContext;
use App\Models\User;
use App\Services\Intake\IntakeSourceContextRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('source context recorder is idempotent and can later link a biodata intake', function () {
    $user = User::factory()->create();
    $intake = createSourceContextIntake($user, [
        'raw_ocr_text' => 'Do not mutate this OCR text',
        'parsed_json' => ['core' => ['full_name' => 'Context Candidate']],
    ]);
    $recorder = app(IntakeSourceContextRecorder::class);

    $first = $recorder->record([
        'source_type' => 'invalid-source',
        'source_surface' => 'invalid-surface',
        'actor_type' => 'invalid-actor',
        'actor_user_id' => $user->id,
        'idempotency_key' => 'source-context-one',
        'source_meta_json' => ['upload_channel' => 'office'],
    ]);
    $second = $recorder->recordForIntake($intake, [
        'source_type' => IntakeSourceContext::SOURCE_ADMIN_BULK,
        'source_surface' => IntakeSourceContext::SURFACE_ADMIN_PANEL,
        'actor_type' => IntakeSourceContext::ACTOR_ADMIN,
        'actor_user_id' => $user->id,
        'idempotency_key' => 'source-context-one',
    ]);

    expect($second->id)->toBe($first->id)
        ->and(IntakeSourceContext::count())->toBe(1)
        ->and($second->fresh()->biodata_intake_id)->toBe($intake->id)
        ->and($second->fresh()->source_type)->toBe(IntakeSourceContext::SOURCE_SYSTEM)
        ->and($second->fresh()->source_surface)->toBeNull()
        ->and($second->fresh()->actor_type)->toBe(IntakeSourceContext::ACTOR_UNKNOWN)
        ->and($second->fresh()->source_meta_json)->toBe(['upload_channel' => 'office'])
        ->and($intake->fresh()->raw_ocr_text)->toBe('Do not mutate this OCR text')
        ->and($intake->fresh()->parsed_json)->toBe(['core' => ['full_name' => 'Context Candidate']]);
});

test('biodata intake exposes source context relationship', function () {
    $user = User::factory()->create();
    $intake = createSourceContextIntake($user);
    $context = app(IntakeSourceContextRecorder::class)->recordForIntake($intake, [
        'source_type' => IntakeSourceContext::SOURCE_USER_APP,
        'source_surface' => IntakeSourceContext::SURFACE_MOBILE_APP,
        'actor_type' => IntakeSourceContext::ACTOR_PROFILE_USER,
        'actor_user_id' => $user->id,
    ]);

    expect($intake->sourceContexts()->count())->toBe(1)
        ->and($intake->sourceContexts()->first()->is($context))->toBeTrue()
        ->and($context->biodataIntake->is($intake))->toBeTrue()
        ->and($context->actorUser->is($user))->toBeTrue();
});

function createSourceContextIntake(User $user, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
