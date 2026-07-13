<?php

use App\Models\AdminSetting;
use App\Services\Intake\IntakeOcrEnsembleGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '0');
});

it('defaults ocr ensemble to disabled', function () {
    expect(app(IntakeOcrEnsembleGate::class)->isEnabled())->toBeFalse();
});

it('reads ocr ensemble flag from admin settings', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, '1');

    expect(app(IntakeOcrEnsembleGate::class)->isEnabled())->toBeTrue();
});
