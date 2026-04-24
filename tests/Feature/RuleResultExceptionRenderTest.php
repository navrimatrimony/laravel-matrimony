<?php

use App\Exceptions\RuleResultException;
use App\Support\ErrorFactory;
use Illuminate\Support\Facades\Route;

test('rule result exception returns json for expects json', function () {
    Route::middleware('web')->get('/__test-rule-result-exception', function () {
        throw new RuleResultException(ErrorFactory::generic());
    });

    $this->getJson('/__test-rule-result-exception')
        ->assertStatus(422)
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('code', 'GENERIC_ERROR');
});

test('rule result exception returns redirect flash for web', function () {
    Route::middleware('web')->get('/__test-rule-result-exception-web', function () {
        throw new RuleResultException(ErrorFactory::generic());
    });

    $this->get('/__test-rule-result-exception-web')
        ->assertSessionHas('error')
        ->assertSessionHas('rule_action', []);
});
