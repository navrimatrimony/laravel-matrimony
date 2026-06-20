<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterGender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class GenderLookupController extends Controller
{
    private const MATRIMONY_LABELS_MR = [
        'male' => 'वर',
        'female' => 'वधू',
    ];

    /**
     * GET /api/v1/genders
     * Returns active governed genders for mobile clients.
     */
    public function index(): JsonResponse
    {
        $hasLabelMr = Schema::hasColumn('master_genders', 'label_mr');
        $columns = ['id', 'key', 'label'];
        if ($hasLabelMr) {
            $columns[] = 'label_mr';
        }

        $genders = MasterGender::query()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN `key` = 'male' THEN 1 WHEN `key` = 'female' THEN 2 ELSE 3 END")
            ->orderBy('label')
            ->get($columns)
            ->map(function (MasterGender $gender) use ($hasLabelMr) {
                return [
                    'id' => $gender->id,
                    'key' => $gender->key,
                    'label' => $gender->label,
                    'label_mr' => self::MATRIMONY_LABELS_MR[$gender->key]
                        ?? ($hasLabelMr ? ($gender->getAttribute('label_mr') ?: null) : null),
                ];
            });

        return response()->json($genders);
    }
}
