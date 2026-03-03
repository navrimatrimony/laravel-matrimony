<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OcrCorrectionPattern;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SSOT Day-30: Admin Governance Panel for OCR correction patterns.
 *
 * Admin can: view patterns, filter by field_key/source/is_active/usage_count, toggle is_active.
 * Admin cannot: edit wrong_pattern/corrected_value, delete correction logs, modify intake.
 * All actions write admin_audit_logs entry.
 */
class OcrPatternController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureCanManageOcrPatterns();

        $query = OcrCorrectionPattern::query();

        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('field_key')) {
            $query->where('field_key', 'like', '%' . $request->input('field_key') . '%');
        }
        if ($request->filled('usage_count_min')) {
            $query->where('usage_count', '>=', (int) $request->input('usage_count_min'));
        }

        $patterns = $query->orderBy('usage_count', 'desc')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.ocr-patterns.index', compact('patterns'));
    }

    public function toggleActive(Request $request, OcrCorrectionPattern $pattern)
    {
        $this->ensureCanManageOcrPatterns();

        DB::transaction(function () use ($request, $pattern) {
            $before = (bool) $pattern->is_active;
            $after  = ! $before;

            $pattern->is_active = $after;
            $pattern->save();

            $reason = sprintf(
                'OCR pattern toggle: id=%d, field_key=%s, source=%s, is_active: %s -> %s',
                $pattern->id,
                (string) $pattern->field_key,
                (string) $pattern->source,
                $before ? 'true' : 'false',
                $after ? 'true' : 'false'
            );

            AuditLogService::log(
                $request->user(),
                'ocr_pattern_toggle_active',
                'OcrCorrectionPattern',
                (int) $pattern->id,
                $reason,
                false
            );
        });

        return redirect()->route('admin.ocr-patterns.index', request()->only(['source', 'is_active', 'field_key', 'usage_count_min', 'page']))
            ->with('success', 'Pattern status updated and audit logged.');
    }

    protected function ensureCanManageOcrPatterns(): void
    {
        if (! auth()->user()) {
            abort(403, 'You do not have permission to manage OCR patterns.');
        }
    }
}
