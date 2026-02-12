<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SeriousIntent;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminSeriousIntentController extends Controller
{
    public function index()
    {
        $this->ensureCanManageSeriousIntents();
        $intents = SeriousIntent::withTrashed()->orderBy('name')->get();
        return view('admin.serious-intents.index', compact('intents'));
    }

    public function store(Request $request)
    {
        $this->ensureCanManageSeriousIntents();
        $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('serious_intents', 'name')->whereNull('deleted_at')],
        ]);

        $existingTrashed = SeriousIntent::onlyTrashed()->where('name', $request->name)->first();
        if ($existingTrashed) {
            return redirect()->route('admin.serious-intents.restore-confirm', $existingTrashed->id)
                ->with('info', 'Serious intent exists but inactive. Restore instead.');
        }

        SeriousIntent::create(['name' => $request->name]);
        return redirect()->route('admin.serious-intents.index')
            ->with('success', 'Serious intent created.');
    }

    public function update(Request $request, $id)
    {
        $this->ensureCanManageSeriousIntents();
        $intent = SeriousIntent::findOrFail($id);

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('serious_intents', 'name')->whereNull('deleted_at')->ignore($intent->id),
            ],
        ]);
        $request->offsetUnset('slug');

        $intent->update(['name' => $request->name]);
        return redirect()->route('admin.serious-intents.index')
            ->with('success', 'Serious intent updated.');
    }

    public function destroy(Request $request, $id)
    {
        $this->ensureCanManageSeriousIntents();
        $request->validate(['reason' => ['required', 'string', 'min:1']]);

        $intent = SeriousIntent::findOrFail($id);
        $intent->delete();
        AuditLogService::log(
            $request->user(),
            'serious_intent_removed',
            'SeriousIntent',
            (int) $id,
            $request->reason,
            false
        );
        return redirect()->route('admin.serious-intents.index')
            ->with('success', 'Serious intent removed (soft delete).');
    }

    public function restoreConfirm($id)
    {
        $this->ensureCanManageSeriousIntents();
        $intent = SeriousIntent::onlyTrashed()->findOrFail($id);
        return view('admin.serious-intents.restore-confirm', compact('intent'));
    }

    public function restore(Request $request, $id)
    {
        $this->ensureCanManageSeriousIntents();
        $request->validate(['reason' => ['required', 'string', 'min:1']]);

        $intent = SeriousIntent::onlyTrashed()->findOrFail($id);
        $intent->restore();
        AuditLogService::log(
            $request->user(),
            'serious_intent_restored',
            'SeriousIntent',
            (int) $id,
            $request->reason,
            false
        );
        return redirect()->route('admin.serious-intents.index')
            ->with('success', 'Serious intent restored.');
    }

    protected function ensureCanManageSeriousIntents(): void
    {
        $admin = auth()->user();

        if (!$admin) {
            abort(403, 'You do not have permission to manage serious intents.');
        }

        if (method_exists($admin, 'isSuperAdmin') && $admin->isSuperAdmin()) {
            return;
        }

        $cap = DB::table('admin_capabilities')
            ->where('admin_id', $admin->id)
            ->first();

        if (!$cap || !$cap->can_manage_serious_intents) {
            abort(403, 'You do not have permission to manage serious intents.');
        }
    }
}
