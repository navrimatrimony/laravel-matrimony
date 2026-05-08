<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MrLocalizationFillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use InvalidArgumentException;

class MrLocalizationFillController extends Controller
{
    public function index(Request $request, MrLocalizationFillService $service): View|RedirectResponse
    {
        $table = (string) $request->query('table', '');
        $base = (string) $request->query('base', '');
        $mr = (string) $request->query('mr', '');

        if ($table === '' || $base === '' || $mr === '') {
            return redirect()
                ->route('admin.data-engine.marathi-columns')
                ->with('error', 'Pick a column from the Marathi report (Fill pending link).');
        }

        try {
            $pair = $service->assertValidPair($table, $base, $mr);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        $counts = $service->countsForPair($pair['table'], $pair['base'], $pair['mr']);
        $rows = $service->pendingQuery($pair['table'], $pair['base'], $pair['mr'])
            ->paginate(50)
            ->withQueryString();

        return view('admin.data-engine.mr-fill', [
            'pair' => $pair,
            'counts' => $counts,
            'rows' => $rows,
            'duplicateScopedByParent' => Schema::hasColumn($pair['table'], 'parent_id'),
            'show_parent_id' => Schema::hasColumn($pair['table'], 'parent_id'),
            'show_type' => Schema::hasColumn($pair['table'], 'type'),
        ]);
    }

    public function update(Request $request, int $row, MrLocalizationFillService $service): RedirectResponse
    {
        $validated = $request->validate([
            'table' => ['required', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'base' => ['required', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'mr' => ['required', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'marathi' => ['required', 'string', 'max:512'],
        ]);

        try {
            $pair = $service->assertValidPair($validated['table'], $validated['base'], $validated['mr']);
        } catch (InvalidArgumentException $e) {
            abort(404);
        }

        $result = $service->tryUpdate(
            $pair['table'],
            $pair['base'],
            $pair['mr'],
            $row,
            $validated['marathi']
        );

        if ($result['ok']) {
            return redirect()->back()->with('status', 'Marathi name saved.');
        }

        return redirect()->back()->with('error', $result['message'] ?? 'Could not save.');
    }
}
