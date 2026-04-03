<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldRegistry;
use App\Services\ExtendedFieldDependencyService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Admin field registry / extended field management (moved from AdminController, Phase 2)
|--------------------------------------------------------------------------
*/
class AdminFieldRegistryController extends Controller
{
    /**
     * Phase-3 Day 1 — Field Registry (read-only). CORE fields only.
     */
    public function fieldRegistryIndex()
    {
        $fields = FieldRegistry::where('field_type', 'CORE')
            ->orderBy('category')
            ->orderBy('display_order')
            ->get();

        return view('admin.field-registry.index', ['fields' => $fields]);
    }

    /**
     * Phase-3 Day 2 — EXTENDED Fields list (read-only).
     */
    public function extendedFieldsIndex()
    {
        $fields = FieldRegistry::where('field_type', 'EXTENDED')
            ->orderBy('category')
            ->orderBy('display_order')
            ->get();

        return view('admin.field-registry.extended.index', ['fields' => $fields]);
    }

    /**
     * Phase-3 Day 2 — EXTENDED Field creation form.
     */
    public function extendedFieldsCreate()
    {
        $extendedFields = FieldRegistry::where('field_type', 'EXTENDED')
            ->orderBy('field_key')
            ->get();

        return view('admin.field-registry.extended.create', ['extendedFields' => $extendedFields]);
    }

    /**
     * Phase-3 Day 2 — Store new EXTENDED field definition.
     */
    public function extendedFieldsStore(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'field_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:field_registry,field_key'],
            'data_type' => ['required', 'in:text,number,date,boolean,select'],
            'display_label' => ['required', 'string', 'max:128'],
            'category' => ['nullable', 'string', 'max:64'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'parent_field_key' => ['nullable', 'string', 'max:64'],
            'dependency_type' => ['nullable', 'in:equals,present'],
            'dependency_value' => ['nullable', 'string', 'max:255'],
        ], [
            'field_key.regex' => 'Field key must contain only lowercase letters, numbers, and underscores.',
            'field_key.unique' => 'This field key already exists.',
            'data_type.in' => 'Data type must be one of: text, number, date, boolean, select.',
        ]);

        $parentKey = isset($validated['parent_field_key']) && $validated['parent_field_key'] !== '' ? trim($validated['parent_field_key']) : null;
        $depType = $validated['dependency_type'] ?? null;
        $depValue = $validated['dependency_value'] ?? null;
        if ($parentKey !== null) {
            $tempField = new FieldRegistry;
            $tempField->field_key = $validated['field_key'];
            $tempField->field_type = 'EXTENDED';
            ExtendedFieldDependencyService::validateDependency(
                $tempField,
                $parentKey,
                ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depValue),
                'Dependency '
            );
        }
        $condition = $parentKey !== null
            ? ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depType === 'equals' ? $depValue : null)
            : null;

        FieldRegistry::create([
            'field_key' => $validated['field_key'],
            'field_type' => 'EXTENDED',
            'data_type' => $validated['data_type'],
            'display_label' => $validated['display_label'],
            'category' => $validated['category'] ?? 'basic',
            'display_order' => $validated['display_order'] ?? 0,
            'is_enabled' => true,
            'is_mandatory' => false,
            'is_searchable' => false,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'is_archived' => false,
            'parent_field_key' => $parentKey,
            'dependency_condition' => $condition,
        ]);

        return redirect()->route('admin.field-registry.extended.index')
            ->with('success', 'EXTENDED field created successfully.');
    }

    /**
     * Day 8: Archive field (soft). No delete. Hidden from new entry.
     */
    public function archiveFieldRegistry(FieldRegistry $field): \Illuminate\Http\RedirectResponse
    {
        if (! auth()->check() || ! auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }
        $field->update(['is_archived' => true]);

        return redirect()->back()->with('success', 'Field archived. Existing profile values unchanged.');
    }

    /**
     * Day 8: Unarchive field. Reactivate for new entry.
     */
    public function unarchiveFieldRegistry(FieldRegistry $field): \Illuminate\Http\RedirectResponse
    {
        if (! auth()->check() || ! auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }
        $field->update(['is_archived' => false]);

        return redirect()->back()->with('success', 'Field unarchived.');
    }

    /**
     * Day 9/10: Bulk update EXTENDED fields — display_order, is_enabled, dependency (Day 10). field_key not modified.
     */
    public function extendedFieldsUpdateBulk(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'fields' => 'required|array',
            'fields.*.id' => 'required|integer|exists:field_registry,id',
            'fields.*.display_order' => 'required|integer|min:0',
            'fields.*.is_enabled' => 'sometimes|in:0,1',
            'fields.*.parent_field_key' => 'nullable|string|max:64',
            'fields.*.dependency_type' => 'nullable|in:equals,present',
            'fields.*.dependency_value' => 'nullable|string|max:255',
        ]);

        foreach ($request->input('fields', []) as $row) {
            $field = FieldRegistry::find($row['id']);
            if (! $field || $field->field_type !== 'EXTENDED') {
                continue;
            }
            $displayOrder = (int) $row['display_order'];
            $isEnabled = isset($row['is_enabled']) && $row['is_enabled'] === '1';
            $parentKey = isset($row['parent_field_key']) && $row['parent_field_key'] !== '' ? trim($row['parent_field_key']) : null;
            $depType = $row['dependency_type'] ?? null;
            $depValue = $row['dependency_value'] ?? null;
            if ($parentKey !== null) {
                ExtendedFieldDependencyService::validateDependency(
                    $field,
                    $parentKey,
                    ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depValue),
                    'Dependency '
                );
            }
            $condition = $parentKey !== null
                ? ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depType === 'equals' ? $depValue : null)
                : null;
            $field->update([
                'display_order' => $displayOrder,
                'is_enabled' => $isEnabled,
                'parent_field_key' => $parentKey,
                'dependency_condition' => $condition,
            ]);
        }

        return redirect()->route('admin.field-registry.extended.index')
            ->with('success', 'EXTENDED field order and visibility updated.');
    }
}
