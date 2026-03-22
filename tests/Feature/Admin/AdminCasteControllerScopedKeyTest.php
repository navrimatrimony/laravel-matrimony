<?php

use App\Http\Controllers\Admin\AdminCasteController;
use App\Models\Caste;
use App\Models\Religion;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;
use Illuminate\Http\Request;

test('same caste key under different religions is allowed', function () {
    $slugger = app(ReligionCasteSubcasteSlugger::class);
    $r1 = Religion::create(['key' => 'rel-a-scoped', 'label' => 'Rel A Scoped', 'is_active' => true]);
    $r2 = Religion::create(['key' => 'rel-b-scoped', 'label' => 'Rel B Scoped', 'is_active' => true]);

    $controller = app(AdminCasteController::class);

    $controller->store(Request::create('/x', 'POST', [
        'religion_id' => $r1->id,
        'label' => 'Rajput Scoped',
    ]), $slugger);

    $controller->store(Request::create('/x', 'POST', [
        'religion_id' => $r2->id,
        'label' => 'Rajput Scoped',
    ]), $slugger);

    expect(Caste::where('key', 'rajput-scoped')->count())->toBe(2);
});

test('duplicate key within same religion is rejected', function () {
    $slugger = app(ReligionCasteSubcasteSlugger::class);
    $r = Religion::create(['key' => 'rel-dup-scoped', 'label' => 'Rel Dup Scoped', 'is_active' => true]);

    $controller = app(AdminCasteController::class);

    $controller->store(Request::create('/x', 'POST', [
        'religion_id' => $r->id,
        'label' => 'Hello World',
    ]), $slugger);

    expect(Caste::where('religion_id', $r->id)->count())->toBe(1);

    $controller->store(Request::create('/x', 'POST', [
        'religion_id' => $r->id,
        'label' => 'Hello-World',
    ]), $slugger);

    expect(Caste::where('religion_id', $r->id)->count())->toBe(1);
});
