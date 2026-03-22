<?php

use App\Services\PartnerPreferenceSnapshotBuilder;
use Illuminate\Http\Request;

test('partner_profile_with_children omitted from snapshot when not posted', function () {
    $r = Request::create('/fake', 'POST', [
        'preferred_age_min' => 22,
        'preferred_age_max' => 28,
    ]);
    $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($r);
    expect($row)->not->toHaveKey('partner_profile_with_children');
});

test('partner_profile_with_children included when posted', function () {
    $r = Request::create('/fake', 'POST', [
        'preferred_age_min' => 22,
        'preferred_age_max' => 28,
        'partner_profile_with_children' => 'yes_if_live_separate',
    ]);
    $row = PartnerPreferenceSnapshotBuilder::validateAndBuildRow($r);
    expect($row['partner_profile_with_children'])->toBe('yes_if_live_separate');
});
