<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Religion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'label' => ['required', 'string', 'max:255', Rule::unique('religions', 'label')],
        ]);
        $label = trim($request->input('label'));
        Religion::create([
            'key' => Str::slug($label),
            'label' => $label,
            'is_active' => true,
        ]);
        return redirect()->route('admin.master.religions.index')->with('success', 'Religion added.');
    }

    public function edit(Religion $religion): View
    {
        return view('admin.master.religions.edit', compact('religion'));
    }

    public function update(Request $request, Religion $religion): RedirectResponse
    {
        $request->validate([
            'label' => ['required', 'string', 'max:255', Rule::unique('religions', 'label')->ignore($religion->id)],
        ]);
        $label = trim($request->input('label'));
        $religion->update(['key' => Str::slug($label), 'label' => $label]);
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
