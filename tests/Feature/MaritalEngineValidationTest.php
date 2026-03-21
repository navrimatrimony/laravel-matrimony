<?php

use App\Models\MatrimonyProfile;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MasterChildLivingWith;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\MasterLookupSeeder::class);
});

test('divorced without has_children returns validation error', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $divorcedId = MasterMaritalStatus::where('key', 'divorced')->value('id');
    $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
    if (! $divorcedId || ! $genderId) {
        $this->markTestSkipped('Master lookups not seeded.');
    }

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), [
        'full_name' => $profile->full_name,
        'gender_id' => $genderId,
        'marital_status_id' => $divorcedId,
        'marriages' => [
            ['id' => null, 'marriage_year' => '', 'divorce_year' => '', 'divorce_status' => ''],
        ],
    ]);

    $response->assertSessionHasErrors('has_children');
});

test('divorced has_children yes with zero children rows returns validation error', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $divorcedId = MasterMaritalStatus::where('key', 'divorced')->value('id');
    $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
    if (! $divorcedId || ! $genderId) {
        $this->markTestSkipped('Master lookups not seeded.');
    }

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), [
        'full_name' => $profile->full_name,
        'gender_id' => $genderId,
        'marital_status_id' => $divorcedId,
        'has_children' => '1',
        'marriages' => [
            ['id' => null, 'marriage_year' => '', 'divorce_year' => '', 'divorce_status' => ''],
        ],
        'children' => [],
    ]);

    $response->assertSessionHasErrors('children');
});

test('divorced has_children no deletes existing children rows', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $divorcedId = MasterMaritalStatus::where('key', 'divorced')->value('id');
    $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
    $livingWithId = MasterChildLivingWith::where('key', 'with_parent')->value('id') ?? MasterChildLivingWith::first()?->id;
    if (! $divorcedId || ! $genderId) {
        $this->markTestSkipped('Master lookups not seeded.');
    }

    DB::table('profile_children')->insert([
        'profile_id' => $profile->id,
        'gender' => 'male',
        'age' => 10,
        'child_living_with_id' => $livingWithId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('profile_children')->insert([
        'profile_id' => $profile->id,
        'gender' => 'female',
        'age' => 8,
        'child_living_with_id' => $livingWithId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->assertSame(2, DB::table('profile_children')->where('profile_id', $profile->id)->count());

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), [
        'full_name' => $profile->full_name,
        'gender_id' => $genderId,
        'marital_status_id' => $divorcedId,
        'has_children' => '0',
        'marriages' => [
            ['id' => null, 'marriage_year' => '2010', 'divorce_year' => '2015', 'divorce_status' => ''],
        ],
        'children' => [],
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertSame(0, DB::table('profile_children')->where('profile_id', $profile->id)->count());
});

test('year sanity divorce_year less than marriage_year returns validation error', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $divorcedId = MasterMaritalStatus::where('key', 'divorced')->value('id');
    $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
    if (! $divorcedId || ! $genderId) {
        $this->markTestSkipped('Master lookups not seeded.');
    }

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), [
        'full_name' => $profile->full_name,
        'gender_id' => $genderId,
        'marital_status_id' => $divorcedId,
        'has_children' => '0',
        'marriages' => [
            ['id' => null, 'marriage_year' => '2010', 'divorce_year' => '2005', 'divorce_status' => ''],
        ],
        'children' => [],
    ]);

    $response->assertSessionHasErrors('marriages.0.divorce_year');
});

test('year sanity separation_year less than marriage_year returns validation error', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $separatedId = MasterMaritalStatus::where('key', 'separated')->value('id');
    $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
    if (! $separatedId || ! $genderId) {
        $this->markTestSkipped('Master lookups not seeded.');
    }

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), [
        'full_name' => $profile->full_name,
        'gender_id' => $genderId,
        'marital_status_id' => $separatedId,
        'has_children' => '0',
        'marriages' => [
            ['id' => null, 'marriage_year' => '2012', 'separation_year' => '2010', 'divorce_status' => ''],
        ],
        'children' => [],
    ]);

    $response->assertSessionHasErrors('marriages.0.separation_year');
});
