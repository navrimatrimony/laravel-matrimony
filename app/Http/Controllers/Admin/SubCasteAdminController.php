<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\MatrimonyProfile;
use App\Models\SubCaste;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SubCasteAdminController extends Controller
{
    /**
     * GET admin/master/sub-castes — list all (master data management).
     */
    public function index(Request $request): View
    {
        $query = SubCaste::with(['caste.religion', 'createdByUser'])->orderBy('label');
        if ($request->filled('caste_id')) {
            $query->where('caste_id', $request->input('caste_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $items = $query->paginate(20);
        $castes = Caste::where('is_active', true)->with('religion')->orderBy('label')->get();
        return view('admin.master.sub_castes.index', compact('items', 'castes'));
    }

    public function edit(SubCaste $sub_caste): View
    {
        $castes = Caste::where('is_active', true)->with('religion')->orderBy('label')->get();
        $mergeTargets = SubCaste::where('id', '!=', $sub_caste->id)->where('is_active', true)->with('caste.religion')->orderBy('label')->get();
        return view('admin.master.sub_castes.edit', ['subCaste' => $sub_caste, 'castes' => $castes, 'mergeTargets' => $mergeTargets]);
    }

    public function update(Request $request, SubCaste $sub_caste): RedirectResponse
    {
        $casteId = (int) $request->input('caste_id');
        $request->validate([
            'caste_id' => ['required', 'exists:castes,id'],
            'label' => ['required', 'string', 'min:2', 'max:255'],
        ]);
        $label = trim($request->input('label'));
        $sub_caste->update([
            'caste_id' => $casteId,
            'label' => $label,
            'key' => Str::slug($label),
        ]);
        return redirect()->route('admin.master.sub-castes.index')->with('success', 'Sub-caste updated.');
    }

    /**
     * Merge this sub-caste into another: reassign profiles to merge_into_id, then soft disable this one.
     */
    public function merge(Request $request, SubCaste $subCaste): RedirectResponse
    {
        $request->validate(['merge_into_id' => ['required', 'exists:sub_castes,id']]);
        $mergeIntoId = (int) $request->input('merge_into_id');
        if ($mergeIntoId === $subCaste->id) {
            return redirect()->back()->with('error', 'Cannot merge into itself.');
        }
        MatrimonyProfile::where('sub_caste_id', $subCaste->id)->update(['sub_caste_id' => $mergeIntoId]);
        $subCaste->update(['is_active' => false]);
        return redirect()->route('admin.master.sub-castes.index')->with('success', 'Sub-caste merged and disabled.');
    }

    public function disable(Request $request, SubCaste $subCaste): RedirectResponse
    {
        $subCaste->update(['is_active' => false]);
        return redirect()->route('admin.master.sub-castes.index')->with('success', 'Sub-caste disabled.');
    }

    public function enable(Request $request, SubCaste $subCaste): RedirectResponse
    {
        $subCaste->update(['is_active' => true]);
        return redirect()->route('admin.master.sub-castes.index')->with('success', 'Sub-caste enabled.');
    }

    /**
     * GET admin/sub-castes/pending — pending approval list (existing).
     */
    public function pending(Request $request): View
    {
        $items = SubCaste::with(['caste.religion', 'createdByUser'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->paginate(20);

        return view('admin.sub_castes.pending', ['items' => $items]);
    }

    /**
     * POST admin/sub-castes/{id}/approve
     */
    public function approve(Request $request, int $id): RedirectResponse
    {
        $subCaste = SubCaste::findOrFail($id);
        if ($subCaste->status !== 'pending') {
            return redirect()->route('admin.sub-castes.pending')->with('error', 'Already approved or not pending.');
        }

        $subCaste->update([
            'status' => 'approved',
            'is_active' => true,
            'approved_by_admin_id' => auth()->id(),
        ]);

        return redirect()->route('admin.sub-castes.pending')->with('success', 'Sub-caste approved.');
    }
}
