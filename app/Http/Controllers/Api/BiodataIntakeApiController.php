<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BiodataIntake;

class BiodataIntakeApiController extends Controller
{
    /**
     * List biodata intakes
     */
    public function index(Request $request)
    {
        $intakes = BiodataIntake::latest()->get();

        return response()->json([
            'success' => true,
            'data' => $intakes,
        ]);
    }

    /**
     * Show single biodata intake
     */
    public function show($id)
    {
        $intake = BiodataIntake::find($id);

        if (!$intake) {
            return response()->json([
                'success' => false,
                'message' => 'Biodata intake not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $intake,
        ]);
    }
}
