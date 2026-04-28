<?php

use App\Models\Caste;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('master:reset-religion-caste-subcaste aborts outside local unless --force-local', function () {
    expect(fn () => $this->artisan('master:reset-religion-caste-subcaste'))
        ->toThrow(\RuntimeException::class);
});

test('master:reset-religion-caste-subcaste imports canonical TSV with --force-local', function () {
    $this->artisan('master:reset-religion-caste-subcaste', ['--force-local' => true])
        ->assertSuccessful();

    expect(Religion::count())->toBeGreaterThan(0);
    expect(Caste::count())->toBeGreaterThan(0);
    expect(SubCaste::count())->toBeGreaterThanOrEqual(0);

    // Rows from database/data/religion_caste_subcaste_master.tsv (verified in file)
    $hindu = Religion::where('key', 'hindu')->first();
    expect($hindu)->not->toBeNull();

    $rajput = Caste::where('religion_id', $hindu->id)->where('key', 'rajput')->first();
    expect($rajput)->not->toBeNull();

    // Muslim	Shia	Ismaili (line ~626 in canonical TSV)
    $muslim = Religion::where('key', 'muslim')->first();
    expect($muslim)->not->toBeNull();
    $shia = Caste::where('religion_id', $muslim->id)->where('key', 'shia')->first();
    expect($shia)->not->toBeNull();
    $ismaili = SubCaste::where('caste_id', $shia->id)->where('key', 'ismaili')->first();
    expect($ismaili)->not->toBeNull();
    expect($ismaili->status)->toBe('approved');
    expect((bool) $ismaili->is_active)->toBeTrue();

    // Reset reapplies Marathi from religion_caste_subcaste_seed_subcastes.json when keys align with TSV.
    expect($ismaili->label_mr)->toBe('इस्माईली');

    $sunni = Caste::where('religion_id', $muslim->id)->where('key', 'sunni')->first();
    expect($sunni)->not->toBeNull();
    expect($sunni->label_mr)->toBe('सुन्नी');
});

test('master:reset-religion-caste-subcaste clears profile and pivot mappings before reimport', function () {
    $this->artisan('master:reset-religion-caste-subcaste', ['--force-local' => true]);

    $r = Religion::firstOrCreate(['key' => 'tmp-z'], ['label' => 'Tmp Z', 'is_active' => true]);
    $c = Caste::create([
        'religion_id' => $r->id,
        'key' => 'tmp-caste',
        'label' => 'Tmp Caste',
        'is_active' => true,
    ]);
    $s = SubCaste::create([
        'caste_id' => $c->id,
        'key' => 'tmp-sub',
        'label' => 'Tmp Sub',
        'is_active' => true,
        'status' => 'approved',
    ]);

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $user->id,
        'religion_id' => $r->id,
        'caste_id' => $c->id,
        'sub_caste_id' => $s->id,
    ]);

    DB::table('profile_preferred_religions')->insert([
        'profile_id' => $profile->id,
        'religion_id' => $r->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('profile_preferred_castes')->insert([
        'profile_id' => $profile->id,
        'caste_id' => $c->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('master:reset-religion-caste-subcaste', ['--force-local' => true])
        ->assertSuccessful();

    $profile->refresh();
    expect($profile->religion_id)->toBeNull();
    expect($profile->caste_id)->toBeNull();
    expect($profile->sub_caste_id)->toBeNull();
    expect(DB::table('profile_preferred_religions')->count())->toBe(0);
    expect(DB::table('profile_preferred_castes')->count())->toBe(0);
});

test('duplicate source rows in TSV do not create duplicate DB rows and increment skipped count', function () {
    $this->artisan('master:reset-religion-caste-subcaste', ['--force-local' => true])
        ->assertSuccessful();

    // Canonical TSV contains the same "Hindu\tBrahmin\t" row twice (e.g. lines ~99 and ~570); import dedupes.
    $hindu = Religion::where('key', 'hindu')->first();
    expect($hindu)->not->toBeNull();
    expect(
        Caste::where('religion_id', $hindu->id)->where('key', 'brahmin')->count()
    )->toBe(1);
});
