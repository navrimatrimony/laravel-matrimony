<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MrLocalizationFillTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_mr_fill_queue(): void
    {
        $this->get(route('admin.data-engine.mr-fill.index', [
            'table' => 'addresses',
            'base' => 'name',
            'mr' => 'name_mr',
        ]))->assertRedirect();
    }

    public function test_non_admin_cannot_open_mr_fill_queue(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'admin_role' => null]);
        $this->actingAs($user)->get(route('admin.data-engine.mr-fill.index', [
            'table' => 'addresses',
            'base' => 'name',
            'mr' => 'name_mr',
        ]))->assertForbidden();
    }

    public function test_admin_receives_404_for_invalid_table(): void
    {
        if (! Schema::hasTable('addresses')) {
            $this->markTestSkipped('addresses table not present');
        }
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.data-engine.mr-fill.index', [
            'table' => 'definitely_not_a_table_zz',
            'base' => 'name',
            'mr' => 'name_mr',
        ]))->assertNotFound();
    }

    public function test_admin_can_open_mr_fill_for_addresses_name_pair(): void
    {
        if (! Schema::hasTable('addresses')) {
            $this->markTestSkipped('addresses table not present');
        }
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.data-engine.mr-fill.index', [
            'table' => 'addresses',
            'base' => 'name',
            'mr' => 'name_mr',
        ]))->assertOk();
    }

    public function test_save_rejects_duplicate_marathi_same_parent_for_addresses(): void
    {
        if (! Schema::hasTable('addresses') || ! Schema::hasColumn('addresses', 'parent_id')) {
            $this->markTestSkipped('addresses.parent_id not present');
        }

        $admin = User::factory()->create(['is_admin' => true]);
        $parentId = DB::table('addresses')->insertGetId([
            'name' => 'Parent place',
            'name_mr' => null,
            'name_en' => null,

            'slug' => 'mr-fill-test-parent-'.uniqid(),
            'hierarchy' => 'taluka',
            'tag' => 'rural',
            'parent_id' => null,
            'level' => 3,
            'state_code' => null,
            'district_code' => null,
            'pincode' => null,
            'lat' => null,
            'lng' => null,
            'lgd_code' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id1 = DB::table('addresses')->insertGetId([
            'name' => 'Village A',
            'name_mr' => null,
            'name_en' => null,

            'slug' => 'mr-fill-test-a-'.uniqid(),
            'hierarchy' => 'village',
            'tag' => 'rural',
            'parent_id' => $parentId,
            'level' => 4,
            'state_code' => null,
            'district_code' => null,
            'pincode' => null,
            'lat' => null,
            'lng' => null,
            'lgd_code' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id2 = DB::table('addresses')->insertGetId([
            'name' => 'Village B',
            'name_mr' => null,
            'name_en' => null,

            'slug' => 'mr-fill-test-b-'.uniqid(),
            'hierarchy' => 'village',
            'tag' => 'rural',
            'parent_id' => $parentId,
            'level' => 4,
            'state_code' => null,
            'district_code' => null,
            'pincode' => null,
            'lat' => null,
            'lng' => null,
            'lgd_code' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mr = 'पर्यायी गाव';
        $this->actingAs($admin)->post(route('admin.data-engine.mr-fill.update', ['row' => $id1]), [
            'table' => 'addresses',
            'base' => 'name',
            'mr' => 'name_mr',
            'marathi' => $mr,
        ])->assertSessionHas('status');

        $this->actingAs($admin)->from(route('admin.data-engine.mr-fill.index', [
            'table' => 'addresses',
            'base' => 'name',
            'mr' => 'name_mr',
        ]))->post(route('admin.data-engine.mr-fill.update', ['row' => $id2]), [
            'table' => 'addresses',
            'base' => 'name',
            'mr' => 'name_mr',
            'marathi' => $mr,
        ])->assertSessionHas('error');

        DB::table('addresses')->whereIn('id', [$id1, $id2, $parentId])->delete();
    }
}
