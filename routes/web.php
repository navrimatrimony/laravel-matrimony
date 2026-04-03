<?php

use App\Models\Caste;
use App\Models\SubCaste;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — Phase 1 surface loaders
|--------------------------------------------------------------------------
| Order: public → member → admin → auth (same as pre-split), then legacy web JSON.
|--------------------------------------------------------------------------
*/

require __DIR__.'/web/public.php';
require __DIR__.'/web/member.php';
require __DIR__.'/web/admin.php';
require __DIR__.'/auth.php';

// Temporary debug route — Phase-5 Day-12 verification. Remove before production.

Route::get('/api/castes/{religionId}', function ($religionId) {
    return Caste::where('religion_id', $religionId)
        ->where('is_active', true)
        ->orderBy('label')
        ->get(['id', 'label', 'label_en', 'label_mr'])
        ->map(function (\App\Models\Caste $c) {
            return [
                'id' => $c->id,
                'label' => $c->display_label,
                'label_en' => $c->label_en ?? $c->label,
                'label_mr' => $c->label_mr,
            ];
        });
});

Route::get('/api/subcastes/{casteId}', function ($casteId) {
    return SubCaste::where('caste_id', $casteId)
        ->where('is_active', true)
        ->where('status', 'approved')
        ->orderBy('label')
        ->get(['id', 'label', 'label_en', 'label_mr'])
        ->map(function (\App\Models\SubCaste $s) {
            return [
                'id' => $s->id,
                'label' => $s->display_label,
                'label_en' => $s->label_en ?? $s->label,
                'label_mr' => $s->label_mr,
            ];
        });
});
