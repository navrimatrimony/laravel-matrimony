<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\Religion;
use App\Modules\Suchak\Services\SuchakCrossSearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CrossSearchController extends Controller
{
    public function index(Request $request, SuchakCrossSearchService $searchService): View
    {
        $account = $request->user()?->suchakAccount;

        if (! $account || ! $searchService->canSearch($account)) {
            abort(403, 'Only verified Suchak accounts can use masked search.');
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'age_min' => ['nullable', 'integer', 'min:18', 'max:100'],
            'age_max' => ['nullable', 'integer', 'min:18', 'max:100'],
            'gender_id' => ['nullable', 'integer', 'min:1'],
            'caste_id' => ['nullable', 'integer', 'min:1'],
            'religion_id' => ['nullable', 'integer', 'min:1'],
            'marital_status_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return view('suchak.search.index', [
            'filters' => $filters,
            'results' => $searchService->search($account, $filters),
            'ownRepresentationOptions' => $searchService->ownRepresentationOptions($account),
            'genderOptions' => MasterGender::query()->where('is_active', true)->orderBy('id')->get(),
            'religionOptions' => Religion::query()->where('is_active', true)->orderBy('label')->get(),
            'casteOptions' => Caste::query()->where('is_active', true)->orderBy('label')->get(),
            'maritalStatusOptions' => MasterMaritalStatus::query()->where('is_active', true)->orderBy('id')->get(),
        ]);
    }
}
