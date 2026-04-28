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
        $data = $request->validate([
            'label_en' => ['required', 'string', 'max:255', Rule::unique('religions', 'label_en')],
            'label_mr' => ['nullable', 'string', 'max:255'],
        ]);
        $labelEn = $slugger->normalizeLabel($data['label_en']);
        $labelMr = isset($data['label_mr']) ? trim((string) $data['label_mr']) : '';
        $labelMr = $labelMr !== '' ? $labelMr : null;

        Religion::create([
            'key' => $slugger->makeKey($labelEn),
            'label' => $labelEn,
            'label_en' => $labelEn,
            'label_mr' => $labelMr,
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
        $data = $request->validate([
            'label_en' => ['required', 'string', 'max:255', Rule::unique('religions', 'label_en')->ignore($religion->id)],
            'label_mr' => ['nullable', 'string', 'max:255'],
        ]);
        $labelEn = $slugger->normalizeLabel($data['label_en']);
        $labelMr = isset($data['label_mr']) ? trim((string) $data['label_mr']) : '';
        $labelMr = $labelMr !== '' ? $labelMr : null;

        $religion->update([
            'key' => $slugger->makeKey($labelEn),
            'label' => $labelEn,
            'label_en' => $labelEn,
            'label_mr' => $labelMr,
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
