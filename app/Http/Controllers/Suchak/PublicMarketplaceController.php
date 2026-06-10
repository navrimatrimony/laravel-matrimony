<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Modules\Suchak\Services\SuchakPublicMarketplaceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicMarketplaceController extends Controller
{
    public function index(Request $request, SuchakPublicMarketplaceService $marketplaceService): View
    {
        $filters = $marketplaceService->filtersFromInput($request->query());

        return view('suchak.marketplace.index', [
            'filters' => $filters,
            'filterOptions' => $marketplaceService->filterOptions(),
            'suchaks' => $marketplaceService->search($filters),
        ]);
    }

    public function show(
        SuchakAccount $suchakAccount,
        SuchakPublicMarketplaceService $marketplaceService,
    ): View {
        $publicProfile = $marketplaceService->publicProfile($suchakAccount);

        abort_if($publicProfile === null, 404);

        return view('suchak.marketplace.show', [
            'publicProfile' => $publicProfile,
        ]);
    }
}
