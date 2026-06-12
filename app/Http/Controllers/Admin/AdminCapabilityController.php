<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Admin\AdminNavigationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCapabilityController extends Controller
{
    public function index()
    {
        $this->ensureSuperAdmin();

        $admins = User::query()
            ->where(function ($query) {
                $query->where('is_admin', true)
                    ->orWhereNotNull('admin_role');
            })
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
                    ...AdminNavigationAccess::defaultCapabilityAttributesFor($admin),
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
            'navSections' => AdminNavigationAccess::sections(),
        ]);
    }

    public function update(Request $request, User $admin)
    {
        $this->ensureSuperAdmin();

        if (! $admin->isAnyAdmin()) {
            abort(404);
        }

        if ($admin->isSuperAdmin()) {
            return redirect()->route('admin.admin-capabilities.index')
                ->with('info', 'Super admin has all admin sections by default.');
        }

        $request->validate([
            'can_manage_verification_tags' => ['sometimes', 'boolean'],
            'can_manage_serious_intents' => ['sometimes', 'boolean'],
            ...AdminNavigationAccess::requestRules(),
        ]);

        $capabilityAttributes = [
            'can_manage_verification_tags' => $request->boolean('can_manage_verification_tags'),
            'can_manage_serious_intents' => $request->boolean('can_manage_serious_intents'),
            ...AdminNavigationAccess::requestAttributes($request),
        ];

        $exists = DB::table('admin_capabilities')
            ->where('admin_id', $admin->id)
            ->exists();

        if ($exists) {
            DB::table('admin_capabilities')
                ->where('admin_id', $admin->id)
                ->update($capabilityAttributes + [
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('admin_capabilities')->insert($capabilityAttributes + [
                'admin_id' => $admin->id,
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
