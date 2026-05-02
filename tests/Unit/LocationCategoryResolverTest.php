<?php

use App\Services\Location\LocationCategoryResolver;

test('pune and mumbai names resolve to metro regardless of type', function () {
    $r = new LocationCategoryResolver;
    expect($r->resolve('Pune', 'district'))->toBe('metro');
    expect($r->resolve('Mumbai', 'district'))->toBe('metro');
    expect($r->resolve('Pune', 'taluka'))->toBe('metro');
});

test('district taluka village suburb city map to categories', function () {
    $r = new LocationCategoryResolver;
    expect($r->resolve('Satara', 'district'))->toBe('city');
    expect($r->resolve('Tasgaon', 'taluka'))->toBe('town');
    expect($r->resolve('X', 'village'))->toBe('village');
    expect($r->resolve('Y', 'suburb'))->toBe('suburban');
    expect($r->resolve('Z', 'city'))->toBe('city');
});

test('country and state are uncategorized', function () {
    $r = new LocationCategoryResolver;
    expect($r->resolve('India', 'country'))->toBeNull();
    expect($r->resolve('Maharashtra', 'state'))->toBeNull();
});
