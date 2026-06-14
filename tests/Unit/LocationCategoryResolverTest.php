<?php

use App\Services\Location\LocationCategoryResolver;

test('pune and mumbai village names resolve to city tag', function () {
    $r = new LocationCategoryResolver;
    expect($r->resolve('Pune', 'village'))->toBe('city');
    expect($r->resolve('Mumbai', 'village'))->toBe('city');
});

test('hierarchy types map only to allowed category tags', function () {
    $r = new LocationCategoryResolver;
    expect($r->resolve('Satara', 'district'))->toBe('city');
    expect($r->resolve('Tasgaon', 'taluka'))->toBe('city');
    expect($r->resolve('X', 'village'))->toBe('rural');
    expect($r->resolve('Y', 'suburb'))->toBeNull();
    expect($r->resolve('Z', 'city'))->toBeNull();
});

test('country and state are uncategorized', function () {
    $r = new LocationCategoryResolver;
    expect($r->resolve('India', 'country'))->toBeNull();
    expect($r->resolve('Maharashtra', 'state'))->toBeNull();
});
