<?php

use App\Services\Intake\OcrEnsembleBenchmarkFieldMatcher;

test('benchmark matcher compares mobile by digits', function () {
    expect(OcrEnsembleBenchmarkFieldMatcher::match('primary_contact_number', '8149379216', '81493 79216'))->toBeTrue()
        ->and(OcrEnsembleBenchmarkFieldMatcher::match('primary_contact_number', '8149379216', '9999999999'))->toBeFalse();
});

test('benchmark matcher uses fuzzy threshold for names', function () {
    expect(OcrEnsembleBenchmarkFieldMatcher::match('full_name', 'अविनाश खोडवे', 'अविनाश खोडवे'))->toBeTrue();
});

test('benchmark matcher normalizes Adv title forms on names', function () {
    expect(OcrEnsembleBenchmarkFieldMatcher::match('full_name', 'स्नेहल शहाजी भोसले', 'Adv. स्नेहल शहाजी भोसले'))->toBeTrue()
        ->and(OcrEnsembleBenchmarkFieldMatcher::match('full_name', 'स्नेहल शहाजी भोसले', 'अॅड. स्नेहल शहाजी भोसले'))->toBeTrue()
        ->and(OcrEnsembleBenchmarkFieldMatcher::match('full_name', 'स्नेहल शहाजी भोसले', 'स्नेहल शहानी भोसले'))->toBeTrue();
});

test('benchmark matcher normalizes gender', function () {
    expect(OcrEnsembleBenchmarkFieldMatcher::match('gender', 'male', 'Male'))->toBeTrue();
});
