<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\Religion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminCasteController extends Controller
{
    public function index(Request $request): View
    {
        $query = Caste::with('religion')->orderBy('label');
        if ($request->filled('religion_id')) {
            $query->where('religion_id', $request->input('religion_id'));
        }
        $items = $query->get();
        $religions = Religion::where('is_active', true)->orderBy('label')->get();
        return view('admin.master.castes.index', compact('items', 'religions'));
    }

    public function create(Request $request): View
    {
        $religions = Religion::where('is_active', true)->orderBy('label')->get();
        return view('admin.master.castes.create', compact('religions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'religion_id' => ['required', 'exists:religions,id'],
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('castes', 'label')->where('religion_id', $request->input('religion_id')),
            ],
        ]);
        $religionId = (int) $request->input('religion_id');
        $label = trim($request->input('label'));
        $key = Str::slug($label);
        if (Caste::where('key', $key)->exists()) {
            $key = $key . '_' . $religionId;
        }
        Caste::create([
            'religion_id' => $religionId,
            'key' => $key,
            'label' => $label,
            'is_active' => true,
        ]);
        return redirect()->route('admin.master.castes.index')->with('success', 'Caste added.');
    }

    public function edit(Caste $caste): View
    {
        $religions = Religion::where('is_active', true)->orderBy('label')->get();
        return view('admin.master.castes.edit', compact('caste', 'religions'));
    }

    public function update(Request $request, Caste $caste): RedirectResponse
    {
        $religionId = (int) $request->input('religion_id', $caste->religion_id);
        $request->validate([
            'religion_id' => ['required', 'exists:religions,id'],
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('castes', 'label')->where('religion_id', $religionId)->ignore($caste->id),
            ],
        ]);
        $label = trim($request->input('label'));
        $caste->update([
            'religion_id' => $religionId,
            'label' => $label,
        ]);
        return redirect()->route('admin.master.castes.index')->with('success', 'Caste updated.');
    }

    public function disable(Request $request, Caste $caste): RedirectResponse
    {
        $caste->update(['is_active' => false]);
        return redirect()->route('admin.master.castes.index')->with('success', 'Caste disabled.');
    }

    public function enable(Request $request, Caste $caste): RedirectResponse
    {
        $caste->update(['is_active' => true]);
        return redirect()->route('admin.master.castes.index')->with('success', 'Caste enabled.');
    }
}
