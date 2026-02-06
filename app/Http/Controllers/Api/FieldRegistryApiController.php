<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FieldRegistry;

class FieldRegistryApiController extends Controller
{
    /**
     * List field registry entries
     */
    public function index(Request $request)
    {
        $fields = FieldRegistry::latest()->get();

        return response()->json([
            'success' => true,
            'data' => $fields,
        ]);
    }
}
