<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FieldRegistry;

class ExtendedFieldApiController extends Controller
{
    /**
     * List extended field definitions
     */
    public function index(Request $request)
    {
        $fields = FieldRegistry::where('field_type', 'EXTENDED')->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $fields,
        ]);
    }
}
