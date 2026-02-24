<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\Request;

class LocationApiController extends Controller
{
    /**
     * City autocomplete. Returns id and name only.
     * GET /cities?search=...
     */
    public function cities(Request $request)
    {
        $request->validate([
            'search' => ['required', 'string', 'min:2'],
        ]);

        $cities = City::where('name', 'like', $request->search . '%')
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name']);

        return response()->json($cities);
    }
}
