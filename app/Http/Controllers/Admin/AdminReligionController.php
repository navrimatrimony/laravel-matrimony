<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Religion;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminReligionController extends Controller
{
    public function index(): View
    {
        $items = Religion::orderBy('label')->get();
        return view('admin.master.religions.index', compact('items'));
    }

    public function create(): View
    {
        return view('admin.master.religions.create');
    }

    public function store(Request $request, ReligionCasteSubcasteSlugger $slugger): RedirectResponse
    {
        $request->validate([
            'label' => ['required', 'string', 'max:255', Rule::unique('religions', 'label')],
        ]);
        $label = $slugger->normalizeLabel($request->input('label'));
        Religion::create([
            'key' => $slugger->makeKey($label),
            'label' => $label,
            'label_en' => $label,
            'label_mr' => null,
            'is_active' => true,
        ]);
        return redirect()->route('admin.master.religions.index')->with('success', 'Religion added.');
    }

    public function edit(Religion $religion): View
    {
        return view('admin.master.religions.edit', compact('religion'));
    }

    public function update(Request $request, Religion $religion, ReligionCasteSubcasteSlugger $slugger): RedirectResponse
    {
        $request->validate([
            'label' => ['required', 'string', 'max:255', Rule::unique('religions', 'label')->ignore($religion->id)],
        ]);
        $label = $slugger->normalizeLabel($request->input('label'));
        $religion->update([
            'key' => $slugger->makeKey($label),
            'label' => $label,
            'label_en' => $label,
        ]);
        return redirect()->route('admin.master.religions.index')->with('success', 'Religion updated.');
    }

    public function disable(Request $request, Religion $religion): RedirectResponse
    {
        $religion->update(['is_active' => false]);
        return redirect()->route('admin.master.religions.index')->with('success', 'Religion disabled.');
    }

    public function enable(Request $request, Religion $religion): RedirectResponse
    {
        $religion->update(['is_active' => true]);
        return redirect()->route('admin.master.religions.index')->with('success', 'Religion enabled.');
    }
}
