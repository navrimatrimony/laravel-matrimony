<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use Illuminate\Http\RedirectResponse;

/**
 * Legacy standalone OCR comparison entry (Phase 5e).
 *
 * Blueprint §7.1 / §13.5: comparison lives only on Correct Candidate.
 * This action redirects to that surface when a bulk item is linked.
 */
class AdminIntakeOcrComparisonController extends Controller
{
    public function show(BiodataIntake $intake): RedirectResponse
    {
        $item = BulkIntakeBatchItem::query()
            ->where('biodata_intake_id', $intake->id)
            ->orderByDesc('id')
            ->first();

        if ($item instanceof BulkIntakeBatchItem) {
            return redirect()
                ->route('admin.bulk-intakes.items.correct-candidate', [
                    $item->bulk_intake_batch_id,
                    $item->id,
                ])
                ->with('info', 'OCR comparison is shown on Correct Candidate (canonical Blueprint surface).');
        }

        abort(404, 'OCR comparison is available only on Correct Candidate for bulk-linked intakes.');
    }
}
