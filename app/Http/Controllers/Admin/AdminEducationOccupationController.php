<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EducationCategory;
use App\Models\EducationDegree;
use App\Models\OccupationCategory;
use App\Models\OccupationMaster;
use App\Models\WorkingWithType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminEducationOccupationController extends Controller
{
    public function index()
    {
        return redirect()->route('admin.master.education.index');
    }

    public function educationIndex(Request $request)
    {
        $degreeSortBy = (string) $request->input('sort_by', 'sort_order');
        $allowedDegreeSorts = ['sort_order', 'code_asc', 'code_desc', 'category'];
        if (! in_array($degreeSortBy, $allowedDegreeSorts, true)) {
            $degreeSortBy = 'sort_order';
        }

        $categorySortBy = (string) $request->input('category_sort', 'sort_order');
        $allowedCategorySorts = ['sort_order', 'name_asc', 'name_desc', 'active_first'];
        if (! in_array($categorySortBy, $allowedCategorySorts, true)) {
            $categorySortBy = 'sort_order';
        }

        $educationUsageCounts = [];
        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
            $educationUsageCounts = DB::table('matrimony_profiles')
                ->whereNotNull('education_degree_id')
                ->select('education_degree_id', DB::raw('COUNT(*) as total'))
                ->groupBy('education_degree_id')
                ->pluck('total', 'education_degree_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        $educationCategories = EducationCategory::query()
            ->with(['degrees' => fn ($q) => $q
                ->whereNotNull('code')
                ->where('code', '!=', '')
                ->orderBy('sort_order')
                ->orderBy('code')]);

        if ($categorySortBy === 'name_asc') {
            $educationCategories->orderBy('name');
        } elseif ($categorySortBy === 'name_desc') {
            $educationCategories->orderByDesc('name');
        } elseif ($categorySortBy === 'active_first') {
            $educationCategories->orderByDesc('is_active')->orderBy('name');
        } else {
            $educationCategories->orderBy('sort_order')->orderBy('name');
        }

        $educationCategories = $educationCategories
            ->get();

        $educationDegrees = EducationDegree::query()
            ->with('category:id,name')
            ->whereNotNull('code')
            ->where('code', '!=', '');

        if ($degreeSortBy === 'code_asc') {
            $educationDegrees->orderBy('code');
        } elseif ($degreeSortBy === 'code_desc') {
            $educationDegrees->orderByDesc('code');
        } elseif ($degreeSortBy === 'category') {
            $educationDegrees
                ->join('education_categories as ec', 'ec.id', '=', 'education_degrees.category_id')
                ->orderBy('ec.name')
                ->orderBy('education_degrees.code')
                ->select('education_degrees.*');
        } else {
            $educationDegrees
                ->orderBy('sort_order')
                ->orderBy('code');
        }

        return view('admin.master.education.index', [
            'educationCategories' => $educationCategories,
            'educationDegrees' => $educationDegrees->get(),
            'educationUsageCounts' => $educationUsageCounts,
            'currentDegreeSort' => $degreeSortBy,
            'currentEducationCategorySort' => $categorySortBy,
        ]);
    }

    public function occupationIndex(Request $request)
    {
        $hasOccupationSortOrder = Schema::hasTable('occupation_master') && Schema::hasColumn('occupation_master', 'sort_order');
        $defaultOccupationSort = $hasOccupationSortOrder ? 'sort_order' : 'name_asc';

        $sortBy = (string) $request->input('sort_by', $defaultOccupationSort);
        $allowedSorts = ['name_asc', 'name_desc', 'category', 'usage_desc'];
        if ($hasOccupationSortOrder) {
            $allowedSorts[] = 'sort_order';
        }
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = $defaultOccupationSort;
        }

        $categorySortBy = (string) $request->input('category_sort', 'sort_order');
        $allowedCategorySorts = ['sort_order', 'name_asc', 'name_desc', 'workplace'];
        if (! in_array($categorySortBy, $allowedCategorySorts, true)) {
            $categorySortBy = 'sort_order';
        }

        $occupationUsageCounts = [];
        foreach ([
            ['table' => 'matrimony_profiles', 'column' => 'occupation_master_id'],
            ['table' => 'matrimony_profiles', 'column' => 'father_occupation_master_id'],
            ['table' => 'matrimony_profiles', 'column' => 'mother_occupation_master_id'],
            ['table' => 'profile_siblings', 'column' => 'occupation_master_id'],
            ['table' => 'profile_relatives', 'column' => 'occupation_master_id'],
            ['table' => 'profile_sibling_spouses', 'column' => 'occupation_master_id'],
        ] as $ref) {
            if (! Schema::hasTable($ref['table']) || ! Schema::hasColumn($ref['table'], $ref['column'])) {
                continue;
            }
            $rows = DB::table($ref['table'])
                ->whereNotNull($ref['column'])
                ->select($ref['column'].' as occupation_id', DB::raw('COUNT(*) as total'))
                ->groupBy($ref['column'])
                ->pluck('total', 'occupation_id')
                ->all();
            foreach ($rows as $id => $count) {
                $iid = (int) $id;
                $occupationUsageCounts[$iid] = (int) ($occupationUsageCounts[$iid] ?? 0) + (int) $count;
            }
        }

        $occupationCategories = OccupationCategory::query()
            ->with([
                'workingWithType:id,name',
                'occupations' => fn ($q) => $q->orderBy('name'),
            ]);

        if ($categorySortBy === 'name_asc') {
            $occupationCategories->orderBy('name');
        } elseif ($categorySortBy === 'name_desc') {
            $occupationCategories->orderByDesc('name');
        } elseif ($categorySortBy === 'workplace') {
            $occupationCategories
                ->leftJoin('working_with_types as wwt', 'wwt.id', '=', 'occupation_categories.legacy_working_with_type_id')
                ->orderBy('wwt.name')
                ->orderBy('occupation_categories.name')
                ->select('occupation_categories.*');
        } else {
            $occupationCategories->orderBy('sort_order')->orderBy('name');
        }

        $occupationCategories = $occupationCategories
            ->get();

        $occupations = OccupationMaster::query()
            ->with('category:id,name');

        if ($sortBy === 'sort_order' && $hasOccupationSortOrder) {
            $occupations
                ->orderBy('sort_order')
                ->orderBy('name');
        } elseif ($sortBy === 'name_desc') {
            $occupations->orderByDesc('name');
        } elseif ($sortBy === 'category') {
            $occupations
                ->join('occupation_categories as oc', 'oc.id', '=', 'occupation_masters.category_id')
                ->orderBy('oc.name')
                ->orderBy('occupation_masters.name')
                ->select('occupation_masters.*');
        } else {
            $occupations->orderBy('name');
        }

        $occupationList = $occupations->get();
        if ($sortBy === 'usage_desc') {
            $occupationList = $occupationList->sortByDesc(function (OccupationMaster $occupation) use ($occupationUsageCounts) {
                return (int) ($occupationUsageCounts[$occupation->id] ?? 0);
            })->values();
        }

        return view('admin.master.occupation.index', [
            'occupationCategories' => $occupationCategories,
            'workingWithTypes' => WorkingWithType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
            'occupationUsageCounts' => $occupationUsageCounts,
            'occupations' => $occupationList,
            'currentOccupationSort' => $sortBy,
            'currentOccupationCategorySort' => $categorySortBy,
            'hasOccupationSortOrder' => $hasOccupationSortOrder,
        ]);
    }

    public function storeEducationCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'in:0,1'],
        ]);

        $slug = Str::slug((string) $data['name']);
        if ($slug === '') {
            $slug = 'education-'.Str::random(6);
        }
        $baseSlug = $slug;
        $i = 1;
        while (EducationCategory::query()->where('slug', $slug)->exists()) {
            $i++;
            $slug = $baseSlug.'-'.$i;
        }

        EducationCategory::query()->create([
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Education category added.');
    }

    public function updateEducationCategory(Request $request, EducationCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'in:0,1'],
        ]);

        $slug = Str::slug((string) $data['name']);
        if ($slug === '') {
            $slug = $category->slug ?: 'education-'.Str::random(6);
        }
        $baseSlug = $slug;
        $i = 1;
        while (EducationCategory::query()->where('id', '!=', $category->id)->where('slug', $slug)->exists()) {
            $i++;
            $slug = $baseSlug.'-'.$i;
        }

        $category->update([
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Education category updated.');
    }

    public function destroyEducationCategory(EducationCategory $category): RedirectResponse
    {
        if ($category->degrees()->exists()) {
            return back()->with('error', 'Cannot delete category with existing degrees. Delete/move degrees first.');
        }

        $category->delete();

        return back()->with('success', 'Education category deleted.');
    }

    public function storeEducationDegree(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('education_categories', 'id')],
            'code' => [
                'required',
                'string',
                'max:128',
                Rule::unique('education_degrees', 'code')->where(fn ($q) => $q->where('category_id', (int) $request->input('category_id'))),
            ],
            'title' => ['nullable', 'string', 'max:128'],
            'full_form' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $code = trim((string) $data['code']);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $code;
        }

        EducationDegree::query()->create([
            'category_id' => (int) $data['category_id'],
            'code' => $code,
            'title' => $title,
            'full_form' => filled($data['full_form'] ?? null) ? trim((string) $data['full_form']) : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return back()->with('success', 'Education degree added.');
    }

    public function updateEducationDegree(Request $request, EducationDegree $degree): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('education_categories', 'id')],
            'code' => [
                'required',
                'string',
                'max:128',
                Rule::unique('education_degrees', 'code')
                    ->where(fn ($q) => $q->where('category_id', (int) $request->input('category_id')))
                    ->ignore($degree->id),
            ],
            'title' => ['nullable', 'string', 'max:128'],
            'full_form' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $code = trim((string) $data['code']);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $code;
        }

        $degree->update([
            'category_id' => (int) $data['category_id'],
            'code' => $code,
            'title' => $title,
            'full_form' => filled($data['full_form'] ?? null) ? trim((string) $data['full_form']) : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return back()->with('success', 'Education degree updated.');
    }

    public function destroyEducationDegree(Request $request, EducationDegree $degree): RedirectResponse
    {
        $data = $request->validate([
            'replacement_degree_id' => [
                'required',
                'integer',
                Rule::exists('education_degrees', 'id'),
                Rule::notIn([$degree->id]),
            ],
        ]);

        $replacementId = (int) $data['replacement_degree_id'];

        DB::transaction(function () use ($degree, $replacementId) {
            if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
                DB::table('matrimony_profiles')
                    ->where('education_degree_id', $degree->id)
                    ->update(['education_degree_id' => $replacementId]);
            }

            $degree->delete();
        });

        return back()->with('success', 'Education degree deleted and reassigned.');
    }

    public function storeOccupationCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'legacy_working_with_type_id' => ['nullable', 'integer', Rule::exists('working_with_types', 'id')],
        ]);

        OccupationCategory::query()->create([
            'name' => trim((string) $data['name']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'legacy_working_with_type_id' => $data['legacy_working_with_type_id'] ?? null,
        ]);

        return back()->with('success', 'Occupation category (workplace) added.');
    }

    public function updateOccupationCategory(Request $request, OccupationCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'legacy_working_with_type_id' => ['nullable', 'integer', Rule::exists('working_with_types', 'id')],
        ]);

        $category->update([
            'name' => trim((string) $data['name']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'legacy_working_with_type_id' => $data['legacy_working_with_type_id'] ?? null,
        ]);

        return back()->with('success', 'Occupation category updated.');
    }

    public function destroyOccupationCategory(OccupationCategory $category): RedirectResponse
    {
        if ($category->occupations()->exists()) {
            return back()->with('error', 'Cannot delete category with occupations. Delete/move occupations first.');
        }

        $category->delete();

        return back()->with('success', 'Occupation category deleted.');
    }

    public function storeOccupation(Request $request): RedirectResponse
    {
        $hasOccupationSortOrder = Schema::hasTable('occupation_master') && Schema::hasColumn('occupation_master', 'sort_order');
        $rules = [
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['required', 'integer', Rule::exists('occupation_categories', 'id')],
        ];
        if ($hasOccupationSortOrder) {
            $rules['sort_order'] = ['nullable', 'integer', 'min:0', 'max:100000'];
        }
        $data = $request->validate($rules);

        $name = trim((string) $data['name']);
        $payload = [
            'name' => $name,
            'normalized_name' => Str::limit(mb_strtolower($name), 160, ''),
            'category_id' => (int) $data['category_id'],
        ];
        if ($hasOccupationSortOrder) {
            $payload['sort_order'] = (int) ($data['sort_order'] ?? 0);
        }
        OccupationMaster::query()->create($payload);

        return back()->with('success', 'Occupation added.');
    }

    public function updateOccupation(Request $request, OccupationMaster $occupation): RedirectResponse
    {
        $hasOccupationSortOrder = Schema::hasTable('occupation_master') && Schema::hasColumn('occupation_master', 'sort_order');
        $rules = [
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['required', 'integer', Rule::exists('occupation_categories', 'id')],
        ];
        if ($hasOccupationSortOrder) {
            $rules['sort_order'] = ['nullable', 'integer', 'min:0', 'max:100000'];
        }
        $data = $request->validate($rules);

        $name = trim((string) $data['name']);
        $payload = [
            'name' => $name,
            'normalized_name' => Str::limit(mb_strtolower($name), 160, ''),
            'category_id' => (int) $data['category_id'],
        ];
        if ($hasOccupationSortOrder) {
            $payload['sort_order'] = (int) ($data['sort_order'] ?? 0);
        }
        $occupation->update($payload);

        return back()->with('success', 'Occupation updated.');
    }

    public function destroyOccupation(Request $request, OccupationMaster $occupation): RedirectResponse
    {
        $data = $request->validate([
            'replacement_occupation_id' => [
                'required',
                'integer',
                Rule::exists('occupation_master', 'id'),
                Rule::notIn([$occupation->id]),
            ],
        ]);

        $replacementId = (int) $data['replacement_occupation_id'];

        DB::transaction(function () use ($occupation, $replacementId) {
            $this->reassignOccupationColumn('matrimony_profiles', 'occupation_master_id', $occupation->id, $replacementId);
            $this->reassignOccupationColumn('matrimony_profiles', 'father_occupation_master_id', $occupation->id, $replacementId);
            $this->reassignOccupationColumn('matrimony_profiles', 'mother_occupation_master_id', $occupation->id, $replacementId);
            $this->reassignOccupationColumn('profile_siblings', 'occupation_master_id', $occupation->id, $replacementId);
            $this->reassignOccupationColumn('profile_relatives', 'occupation_master_id', $occupation->id, $replacementId);
            $this->reassignOccupationColumn('profile_sibling_spouses', 'occupation_master_id', $occupation->id, $replacementId);

            $occupation->delete();
        });

        return back()->with('success', 'Occupation deleted and reassigned.');
    }

    private function reassignOccupationColumn(string $table, string $column, int $fromId, int $toId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->where($column, $fromId)
            ->update([$column => $toId]);
    }
}

