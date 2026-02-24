<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\Request;

class LocationController extends Controller
{
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
