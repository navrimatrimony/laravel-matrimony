<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TranslationAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TranslationController extends Controller
{
    public function __construct(
        private TranslationAdminService $service
    ) {}

    public function index(Request $request): View
    {
        $namespace = $request->query('namespace');
        $search = $request->query('search');
        $list = $this->service->getListForAdmin($namespace, $search);
        $namespaces = $this->service->getNamespaceOptions();

        return view('admin.translations.index', [
            'list' => $list,
            'namespaces' => $namespaces,
            'currentNamespace' => $namespace,
            'search' => $search,
        ]);
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $key = $request->query('key');
        if ($key === null || $key === '') {
            return redirect()->route('admin.translations.index')->with('error', 'Key required.');
        }

        $enFile = $this->service->getKeysFromFiles('en');
        $mrFile = $this->service->getKeysFromFiles('mr');
        $dbEn = \App\Models\Translation::where('locale', 'en')->where('key', $key)->first();
        $dbMr = \App\Models\Translation::where('locale', 'mr')->where('key', $key)->first();

        $valueEn = $dbEn?->value ?? $enFile[$key] ?? '';
        $valueMr = $dbMr?->value ?? $mrFile[$key] ?? '';

        return view('admin.translations.edit', [
            'key' => $key,
            'value_en' => $valueEn,
            'value_mr' => $valueMr,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value_en' => ['nullable', 'string', 'max:1000'],
            'value_mr' => ['nullable', 'string', 'max:1000'],
        ]);

        $key = trim($request->input('key'));
        $this->service->saveKey(
            $key,
            $request->input('value_en', ''),
            $request->input('value_mr', '')
        );

        return redirect()->route('admin.translations.index')->with('success', __('Value updated. Key is read-only.'));
    }

    public function create(): View
    {
        $namespaces = $this->service->getNamespaceOptions();
        return view('admin.translations.create', ['namespaces' => $namespaces]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_.]+$/i'],
            'value_en' => ['required', 'string', 'max:1000'],
            'value_mr' => ['nullable', 'string', 'max:1000'],
        ], [
            'key.regex' => 'Key must contain only letters, numbers, dots and underscores (e.g. components.options.diet.jain_food).',
        ]);

        try {
            $this->service->addAlias(
                trim($request->input('key')),
                $request->input('value_en'),
                $request->input('value_mr', '')
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.translations.index')->with('success', __('New alias added.'));
    }
}
