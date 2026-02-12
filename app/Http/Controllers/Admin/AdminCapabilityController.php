<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCapabilityController extends Controller
{
    public function index()
    {
        $this->ensureSuperAdmin();

        $admins = User::query()
            ->where('is_admin', true)
            ->orderBy('name')
            ->get();

        foreach ($admins as $admin) {
            $exists = DB::table('admin_capabilities')
                ->where('admin_id', $admin->id)
                ->exists();

            if (!$exists) {
                DB::table('admin_capabilities')->insert([
                    'admin_id' => $admin->id,
                    'can_manage_verification_tags' => false,
                    'can_manage_serious_intents' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $capabilities = DB::table('admin_capabilities')
            ->whereIn('admin_id', $admins->pluck('id'))
            ->get()
            ->keyBy('admin_id');

        return view('admin.admin-capabilities.index', [
            'admins' => $admins,
            'capabilities' => $capabilities,
        ]);
    }

    public function update(Request $request, User $admin)
    {
        $this->ensureSuperAdmin();

        if (!$admin->is_admin) {
            abort(404);
        }

        $request->validate([
            'can_manage_verification_tags' => ['sometimes', 'boolean'],
            'can_manage_serious_intents' => ['sometimes', 'boolean'],
        ]);

        $exists = DB::table('admin_capabilities')
            ->where('admin_id', $admin->id)
            ->exists();

        if ($exists) {
            DB::table('admin_capabilities')
                ->where('admin_id', $admin->id)
                ->update([
                    'can_manage_verification_tags' => $request->boolean('can_manage_verification_tags'),
                    'can_manage_serious_intents' => $request->boolean('can_manage_serious_intents'),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('admin_capabilities')->insert([
                'admin_id' => $admin->id,
                'can_manage_verification_tags' => $request->boolean('can_manage_verification_tags'),
                'can_manage_serious_intents' => $request->boolean('can_manage_serious_intents'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return redirect()->route('admin.admin-capabilities.index')
            ->with('success', 'Admin capabilities updated successfully.');
    }

    protected function ensureSuperAdmin(): void
    {
        $adminUser = auth()->user();

        if (!$adminUser || !$adminUser->isSuperAdmin()) {
            abort(403);
        }
    }
}

