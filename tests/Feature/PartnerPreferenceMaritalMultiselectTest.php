<?php

use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use App\Services\PartnerPreferenceSnapshotBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

test('partner preference snapshot stores multiple marital statuses in pivot and clears single column', function () {
    $statuses = MasterMaritalStatus::query()->where('is_active', true)->orderBy('id')->limit(2)->pluck('id')->all();
    expect(count($statuses))->toBeGreaterThanOrEqual(2);

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create();

    $req = Request::create('/fake', 'POST', [
        'preferred_age_min' => 25,
        'preferred_age_max' => 35,
        'preferred_marital_status_ids' => [(string) $statuses[0], (string) $statuses[1]],
    ]);

    $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($req);
    expect($row['preferred_marital_status_ids'])->toHaveCount(2)
        ->and($row['preferred_marital_status_id'])->toBeNull();

    app(MutationService::class)->applyManualSnapshot($profile, ['preferences' => $row], (int) $user->id, 'manual');

    $ids = DB::table('profile_preferred_marital_statuses')->where('profile_id', $profile->id)->pluck('marital_status_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    expect($ids)->toEqual([(int) $statuses[0], (int) $statuses[1]]);

    $col = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->value('preferred_marital_status_id');
    expect($col)->toBeNull();
});

test('legacy single preferred_marital_status_id expands to pivot', function () {
    $id = MasterMaritalStatus::query()->where('is_active', true)->orderBy('id')->value('id');
    expect($id)->not->toBeNull();

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create();

    $req = Request::create('/fake', 'POST', [
        'preferred_age_min' => 22,
        'preferred_age_max' => 30,
        'preferred_marital_status_id' => (string) $id,
    ]);

    $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($req);
    expect($row['preferred_marital_status_ids'])->toEqual([(int) $id])
        ->and($row['preferred_marital_status_id'])->toBe((int) $id);

    app(MutationService::class)->applyManualSnapshot($profile, ['preferences' => $row], (int) $user->id, 'manual');

    $pivot = DB::table('profile_preferred_marital_statuses')->where('profile_id', $profile->id)->pluck('marital_status_id')->all();
    expect($pivot)->toEqual([(int) $id]);
});

test('posting every active marital status normalizes to open to all empty pivot', function () {
    $allIds = MasterMaritalStatus::query()->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    expect(count($allIds))->toBeGreaterThan(0);

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create();

    $req = Request::create('/fake', 'POST', [
        'preferred_age_min' => 20,
        'preferred_age_max' => 40,
        'preferred_marital_status_ids' => $allIds,
    ]);

    $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($req);
    expect($row['preferred_marital_status_ids'])->toBe([])
        ->and($row['preferred_marital_status_id'])->toBeNull();

    app(MutationService::class)->applyManualSnapshot($profile, ['preferences' => $row], (int) $user->id, 'manual');

    expect(DB::table('profile_preferred_marital_statuses')->where('profile_id', $profile->id)->count())->toBe(0);
});
