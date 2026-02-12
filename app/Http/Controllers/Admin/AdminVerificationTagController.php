<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VerificationTag;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminVerificationTagController extends Controller
{
    public function index()
    {
        $this->ensureCanManageVerificationTags();
        $tags = VerificationTag::withTrashed()->orderBy('name')->get();
        return view('admin.verification-tags.index', compact('tags'));
    }

    public function store(Request $request)
    {
        $this->ensureCanManageVerificationTags();
        $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('verification_tags', 'name')->whereNull('deleted_at')],
        ]);

        $existingTrashed = VerificationTag::onlyTrashed()->where('name', $request->name)->first();
        if ($existingTrashed) {
            return redirect()->route('admin.verification-tags.restore-confirm', $existingTrashed->id)
                ->with('info', 'Tag exists but inactive. Restore instead.');
        }

        VerificationTag::create(['name' => $request->name]);
        return redirect()->route('admin.verification-tags.index')
            ->with('success', 'Verification tag created.');
    }

    public function update(Request $request, $id)
    {
        $this->ensureCanManageVerificationTags();
        $tag = VerificationTag::findOrFail($id);

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('verification_tags', 'name')->whereNull('deleted_at')->ignore($tag->id),
            ],
        ]);
        $request->offsetUnset('slug');

        $tag->update(['name' => $request->name]);
        return redirect()->route('admin.verification-tags.index')
            ->with('success', 'Verification tag updated.');
    }

    public function destroy(Request $request, $id)
    {
        $this->ensureCanManageVerificationTags();
        $request->validate(['reason' => ['required', 'string', 'min:1']]);

        $tag = VerificationTag::findOrFail($id);
        $tag->delete();
        AuditLogService::log(
            $request->user(),
            'verification_tag_removed',
            'VerificationTag',
            (int) $id,
            $request->reason,
            false
        );
        return redirect()->route('admin.verification-tags.index')
            ->with('success', 'Verification tag removed (soft delete).');
    }

    public function restoreConfirm($id)
    {
        $this->ensureCanManageVerificationTags();
        $tag = VerificationTag::onlyTrashed()->findOrFail($id);
        return view('admin.verification-tags.restore-confirm', compact('tag'));
    }

    public function restore(Request $request, $id)
    {
        $this->ensureCanManageVerificationTags();
        $request->validate(['reason' => ['required', 'string', 'min:1']]);

        $tag = VerificationTag::onlyTrashed()->findOrFail($id);
        $tag->restore();
        AuditLogService::log(
            $request->user(),
            'verification_tag_restored',
            'VerificationTag',
            (int) $id,
            $request->reason,
            false
        );
        return redirect()->route('admin.verification-tags.index')
            ->with('success', 'Verification tag restored.');
    }

    protected function ensureCanManageVerificationTags(): void
    {
        $admin = auth()->user();

        if (!$admin) {
            abort(403, 'You do not have permission to manage verification tags.');
        }

        if (method_exists($admin, 'isSuperAdmin') && $admin->isSuperAdmin()) {
            return;
        }

        $cap = DB::table('admin_capabilities')
            ->where('admin_id', $admin->id)
            ->first();

        if (!$cap || !$cap->can_manage_verification_tags) {
            abort(403, 'You do not have permission to manage verification tags.');
        }
    }
}
