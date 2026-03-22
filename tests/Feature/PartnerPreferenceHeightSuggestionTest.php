<?php

use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Services\PartnerPreferenceSuggestionService;

test('four inches in cm is rounded from 4 × 2.54', function () {
    expect(PartnerPreferenceSuggestionService::fourInchesCm())->toBe(10);
});

test('male 175 cm suggests preferred height 165–175 cm', function () {
    $male = MasterGender::firstOrCreate(
        ['key' => 'male'],
        ['label' => 'Male', 'is_active' => true]
    );
    $p = new MatrimonyProfile([
        'height_cm' => 175,
        'gender_id' => $male->id,
    ]);
    $p->setRelation('gender', $male);

    $r = PartnerPreferenceSuggestionService::defaultPreferredHeightRangeCm($p);
    expect($r['min'])->toBe(165);
    expect($r['max'])->toBe(175);
});

test('female 160 cm suggests preferred height 160–170 cm', function () {
    $female = MasterGender::firstOrCreate(
        ['key' => 'female'],
        ['label' => 'Female', 'is_active' => true]
    );
    $p = new MatrimonyProfile([
        'height_cm' => 160,
        'gender_id' => $female->id,
    ]);
    $p->setRelation('gender', $female);

    $r = PartnerPreferenceSuggestionService::defaultPreferredHeightRangeCm($p);
    expect($r['min'])->toBe(160);
    expect($r['max'])->toBe(170);
});

test('missing height returns null suggestion', function () {
    $male = MasterGender::firstOrCreate(
        ['key' => 'male'],
        ['label' => 'Male', 'is_active' => true]
    );
    $p = new MatrimonyProfile(['gender_id' => $male->id]);
    $p->setRelation('gender', $male);

    expect(PartnerPreferenceSuggestionService::defaultPreferredHeightRangeCm($p))->toBeNull();
});

test('never_married profile suggests preferred marital status id for never_married master row', function () {
    $never = MasterMaritalStatus::firstOrCreate(
        ['key' => 'never_married'],
        ['label' => 'Never Married', 'is_active' => true]
    );
    $p = new MatrimonyProfile([]);
    $p->setRelation('maritalStatus', $never);

    expect(PartnerPreferenceSuggestionService::defaultPreferredMaritalStatusId($p))->toBe((int) $never->id);
});

test('non-never_married profile suggests open to all for marital preference', function () {
    $div = MasterMaritalStatus::firstOrCreate(
        ['key' => 'divorced'],
        ['label' => 'Divorced', 'is_active' => true]
    );
    $p = new MatrimonyProfile([]);
    $p->setRelation('maritalStatus', $div);

    expect(PartnerPreferenceSuggestionService::defaultPreferredMaritalStatusId($p))->toBeNull();
});

test('missing member marital status yields null preferred marital suggestion', function () {
    $p = new MatrimonyProfile([]);
    $p->setRelation('maritalStatus', null);

    expect(PartnerPreferenceSuggestionService::defaultPreferredMaritalStatusId($p))->toBeNull();
});
