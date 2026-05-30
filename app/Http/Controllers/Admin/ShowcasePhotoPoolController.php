<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterMaritalStatus;
use App\Models\Religion;
use App\Services\AuditLogService;
use App\Services\Showcase\ShowcasePhotoPoolService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShowcasePhotoPoolController extends Controller
{
    public function __construct(
        private readonly ShowcasePhotoPoolService $pool
    ) {}

    public function index(Request $request)
    {
        $religions = Religion::query()
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('label_en')
            ->orderBy('label')
            ->orderBy('id')
            ->get();

        $maritalStatuses = MasterMaritalStatus::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->orderBy('id')
            ->get();

        $filterGender = (string) $request->input('gender', '');
        $filterReligionId = (int) $request->input('religion_id', 0);
        $filterMaritalId = (int) $request->input('marital_status_id', 0);
        $filterAgeBucket = (string) $request->input('age_bucket', '');

        $browsePhotos = [];
        $browseFolder = null;
        $browseCategory = null;

        if ($filterGender !== '' && $filterReligionId > 0 && $filterMaritalId > 0 && $filterAgeBucket !== '') {
            $browseCategory = $this->pool->resolveCategoryFromIds(
                $filterReligionId,
                $filterMaritalId,
                $filterAgeBucket,
                $filterGender
            );
            if ($browseCategory !== null) {
                $browseFolder = $this->pool->relativeFolder(
                    $browseCategory['gender'],
                    $browseCategory['religion_key'],
                    $browseCategory['marital_key'],
                    $browseCategory['age_bucket']
                );
                $browsePhotos = $this->pool->listPhotosInFolder($browseFolder);
            }
        }

        $matrix = $this->enrichMatrixWithMasterIds($this->pool->coverageMatrix());
        $matrixTotalPhotos = array_sum(array_column($matrix, 'total'));
        $matrixExhaustedBuckets = count(array_filter(
            $matrix,
            static fn (array $r): bool => (int) ($r['total'] ?? 0) > 0 && (int) ($r['unused'] ?? 0) === 0
        ));

        return view('admin.showcase-photo-pool.index', [
            'religions' => $religions,
            'maritalStatuses' => $maritalStatuses,
            'genders' => ShowcasePhotoPoolService::GENDERS,
            'ageBuckets' => ShowcasePhotoPoolService::AGE_BUCKETS,
            'filterGender' => $filterGender,
            'filterReligionId' => $filterReligionId,
            'filterMaritalId' => $filterMaritalId,
            'filterAgeBucket' => $filterAgeBucket,
            'browsePhotos' => $browsePhotos,
            'browseFolder' => $browseFolder,
            'browseCategory' => $browseCategory,
            'matrix' => $matrix,
            'matrixTotalPhotos' => $matrixTotalPhotos,
            'matrixBucketCount' => count($matrix),
            'matrixExhaustedBuckets' => $matrixExhaustedBuckets,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'gender' => ['required', Rule::in(ShowcasePhotoPoolService::GENDERS)],
            'religion_id' => ['required', 'integer', 'exists:master_religions,id'],
            'marital_status_id' => ['required', 'integer', 'exists:master_marital_statuses,id'],
            'age_bucket' => ['required', Rule::in(ShowcasePhotoPoolService::AGE_BUCKETS)],
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $category = $this->pool->resolveCategoryFromIds(
            (int) $request->input('religion_id'),
            (int) $request->input('marital_status_id'),
            (string) $request->input('age_bucket'),
            (string) $request->input('gender')
        );
        if ($category === null) {
            return redirect()
                ->route('admin.showcase-photo-pool.index')
                ->withErrors(['religion_id' => __('showcase_photo_pool_admin.invalid_category')])
                ->withInput();
        }

        $files = $request->file('photos', []);
        if (! is_array($files)) {
            $files = [];
        }

        $saved = $this->pool->uploadToCategory(
            $files,
            $category['gender'],
            $category['religion_key'],
            $category['marital_key'],
            $category['age_bucket']
        );

        if ($saved === []) {
            return redirect()
                ->route('admin.showcase-photo-pool.index')
                ->withErrors(['photos' => __('showcase_photo_pool_admin.upload_none_saved')])
                ->withInput();
        }

        AuditLogService::log(
            $request->user(),
            'showcase_photo_pool_upload',
            'ShowcasePhotoPool',
            null,
            count($saved).' file(s) → '.$this->pool->relativeFolder(
                $category['gender'],
                $category['religion_key'],
                $category['marital_key'],
                $category['age_bucket']
            ),
            false
        );

        return redirect()
            ->route('admin.showcase-photo-pool.index', [
                'gender' => $request->input('gender'),
                'religion_id' => $request->input('religion_id'),
                'marital_status_id' => $request->input('marital_status_id'),
                'age_bucket' => $request->input('age_bucket'),
            ])
            ->with('success', __('showcase_photo_pool_admin.upload_success', ['count' => count($saved)]));
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'relative_path' => ['required', 'string', 'max:500'],
        ]);

        $relativePath = (string) $request->input('relative_path');

        try {
            $this->pool->deleteRelativePath($relativePath);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['relative_path' => $e->getMessage()]);
        }

        AuditLogService::log(
            $request->user(),
            'showcase_photo_pool_delete',
            'ShowcasePhotoPool',
            null,
            $relativePath,
            false
        );

        $redirectQuery = array_filter([
            'gender' => $request->input('gender'),
            'religion_id' => $request->filled('religion_id') ? (int) $request->input('religion_id') : null,
            'marital_status_id' => $request->filled('marital_status_id') ? (int) $request->input('marital_status_id') : null,
            'age_bucket' => $request->input('age_bucket'),
        ], static fn ($v) => $v !== null && $v !== '');

        return redirect()
            ->route('admin.showcase-photo-pool.index', $redirectQuery)
            ->with('success', __('showcase_photo_pool_admin.delete_success'));
    }

    /**
     * @param  list<array<string, mixed>>  $matrix
     * @return list<array<string, mixed>>
     */
    private function enrichMatrixWithMasterIds(array $matrix): array
    {
        $religionIdsByKey = Religion::query()->pluck('id', 'key');
        $maritalIdsByKey = MasterMaritalStatus::query()->pluck('id', 'key');

        foreach ($matrix as $i => $row) {
            $matrix[$i]['religion_id'] = $religionIdsByKey[$row['religion_key'] ?? ''] ?? null;
            $matrix[$i]['marital_status_id'] = $maritalIdsByKey[$row['marital_key'] ?? ''] ?? null;
        }

        return $matrix;
    }
}
