<?php

use App\Models\BulkIntakeBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin bulk intake route names exist', function () {
    expect(route('admin.bulk-intakes.index'))->toBe(url('/admin/bulk-intakes'))
        ->and(route('admin.bulk-intakes.create'))->toBe(url('/admin/bulk-intakes/create'))
        ->and(route('admin.bulk-intakes.store'))->toBe(url('/admin/bulk-intakes'))
        ->and(route('admin.bulk-intakes.show', ['bulkIntakeBatch' => 123]))->toBe(url('/admin/bulk-intakes/123'))
        ->and(route('admin.bulk-intakes.queue-free-parse', ['bulkIntakeBatch' => 123]))->toBe(url('/admin/bulk-intakes/123/queue-free-parse'))
        ->and(route('admin.bulk-intakes.items.queue-free-parse', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))->toBe(url('/admin/bulk-intakes/123/items/456/queue-free-parse'));
});

test('admin can access bulk intake index create and show pages', function () {
    $admin = adminBulkIntakeRoutesAdmin();
    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.index'))
        ->assertOk()
        ->assertSee('Bulk Intakes', false);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.create'))
        ->assertOk()
        ->assertSee('New Bulk Intake', false);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Bulk Intake #'.$batch->id, false);
});

test('non admin cannot access admin bulk intake routes', function () {
    $member = User::factory()->create(['is_admin' => false, 'admin_role' => null]);
    $batch = BulkIntakeBatch::create([
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.index'))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.create'))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.store'), [
            'owner_user_id' => $member->id,
            'raw_text' => 'Name: Forbidden',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.queue-free-parse', $batch))
        ->assertForbidden();
});

function adminBulkIntakeRoutesAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}
