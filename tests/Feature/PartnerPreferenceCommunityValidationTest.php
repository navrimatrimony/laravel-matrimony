<?php

use App\Models\Caste;
use App\Models\Religion;
use App\Services\PartnerPreferenceSnapshotBuilder;
use Illuminate\Http\Request;

test('partner preference rejects caste not belonging to selected religions', function () {
    $rA = Religion::firstOrCreate(['key' => 'tmp_partner_a'], ['label' => 'Tmp A', 'is_active' => true]);
    $rB = Religion::firstOrCreate(['key' => 'tmp_partner_b'], ['label' => 'Tmp B', 'is_active' => true]);
    $cB = Caste::create([
        'religion_id' => $rB->id,
        'key' => 'tmp_caste_reject_'.uniqid('', true),
        'label' => 'Caste B',
        'is_active' => true,
    ]);

    $req = Request::create('/test', 'POST', [
        'preferred_age_min' => 22,
        'preferred_age_max' => 30,
        'preferred_religion_ids' => [$rA->id],
        'preferred_caste_ids' => [$cB->id],
    ]);

    expect(fn () => PartnerPreferenceSnapshotBuilder::validateAndBuildRow($req))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('partner preference accepts caste belonging to selected religions', function () {
    $rA = Religion::firstOrCreate(['key' => 'tmp_partner_a2'], ['label' => 'Tmp A2', 'is_active' => true]);
    $cA = Caste::create([
        'religion_id' => $rA->id,
        'key' => 'tmp_caste_ok_'.uniqid('', true),
        'label' => 'Caste A2',
        'is_active' => true,
    ]);

    $req = Request::create('/test', 'POST', [
        'preferred_age_min' => 22,
        'preferred_age_max' => 30,
        'preferred_religion_ids' => [$rA->id],
        'preferred_caste_ids' => [$cA->id],
    ]);

    $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($req);
    expect($row['preferred_caste_ids'])->toContain($cA->id);
});
