<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Services\Intake\IntakeApprovalService;
use Illuminate\Http\Request;

/**
 * Admin queue: review pending_intake_suggestions_json on profiles (no intake context required).
 */
class IntakeReviewController extends Controller
{
    public function index(Request $request, IntakeApprovalService $approvalService)
    {
        $this->authorizeAdmin();

        $profiles = MatrimonyProfile::query()
            ->whereNotNull('pending_intake_suggestions_json')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        $profiles->getCollection()->transform(function (MatrimonyProfile $p) use ($approvalService) {
            $p->setAttribute('pending_suggestions_count', $approvalService->countPendingSuggestions($p));

            return $p;
        });

        return view('admin.intake-review.index', [
            'profiles' => $profiles,
        ]);
    }

    public function show(MatrimonyProfile $profile, IntakeApprovalService $approvalService)
    {
        $this->authorizeAdmin();

        $pending = $profile->pending_intake_suggestions_json;
        if (! is_array($pending) || $pending === [] || $approvalService->countPendingSuggestions($profile) === 0) {
            return redirect()
                ->route('admin.intake.index')
                ->with('info', 'No pending intake suggestions for this profile.');
        }

        $rows = $approvalService->buildReviewRows($profile);

        return view('admin.intake-review.show', [
            'profile' => $profile,
            'reviewRows' => $rows,
        ]);
    }

    public function approve(Request $request, MatrimonyProfile $profile, IntakeApprovalService $approvalService)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', 'max:256'],
        ]);

        $allowed = collect($approvalService->buildReviewRows($profile))->pluck('id')->all();
        $fields = array_values(array_filter(
            $validated['fields'],
            static fn (string $id): bool => in_array($id, $allowed, true)
        ));
        if ($fields === []) {
            return redirect()
                ->route('admin.intake.show', $profile)
                ->with('error', 'No valid suggestions selected.');
        }

        $actorId = (int) $request->user()->id;
        $result = $approvalService->applyApprovedFields($profile, $fields, $actorId);

        $msg = "Applied {$result['applied']} suggestion(s).";
        if ($result['skipped'] !== []) {
            $msg .= ' Skipped: '.implode('; ', $result['skipped']);
        }
        if ($result['errors'] !== []) {
            $msg .= ' Errors: '.implode('; ', $result['errors']);
        }

        return redirect()
            ->route('admin.intake.show', $profile)
            ->with($result['applied'] > 0 ? 'success' : 'info', $msg);
    }

    public function reject(Request $request, MatrimonyProfile $profile, IntakeApprovalService $approvalService)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', 'max:256'],
        ]);

        $allowed = collect($approvalService->buildReviewRows($profile))->pluck('id')->all();
        $fields = array_values(array_filter(
            $validated['fields'],
            static fn (string $id): bool => in_array($id, $allowed, true)
        ));
        if ($fields === []) {
            return redirect()
                ->route('admin.intake.show', $profile)
                ->with('error', 'No valid suggestions selected.');
        }

        $result = $approvalService->rejectFields($profile, $fields);
        $msg = "Removed {$result['removed']} suggestion(s).";
        if ($result['errors'] !== []) {
            $msg .= ' '.implode('; ', $result['errors']);
        }

        $next = $approvalService->countPendingSuggestions($profile->fresh()) === 0
            ? redirect()->route('admin.intake.index')->with('success', $msg)
            : redirect()->route('admin.intake.show', $profile)->with('success', $msg);

        return $next;
    }

    public function approveAll(MatrimonyProfile $profile, Request $request, IntakeApprovalService $approvalService)
    {
        $this->authorizeAdmin();

        $actorId = (int) $request->user()->id;
        $result = $approvalService->applyAll($profile, $actorId);

        $msg = "Applied {$result['applied']} suggestion(s) (approve all).";
        if ($result['skipped'] !== []) {
            $msg .= ' Skipped: '.implode('; ', $result['skipped']);
        }
        if ($result['errors'] !== []) {
            $msg .= ' Errors: '.implode('; ', $result['errors']);
        }

        $next = $approvalService->countPendingSuggestions($profile->fresh()) === 0
            ? redirect()->route('admin.intake.index')->with($result['applied'] > 0 ? 'success' : 'info', $msg)
            : redirect()->route('admin.intake.show', $profile)->with($result['applied'] > 0 ? 'success' : 'info', $msg);

        return $next;
    }

    public function clearAll(MatrimonyProfile $profile, IntakeApprovalService $approvalService)
    {
        $this->authorizeAdmin();

        $approvalService->clearAll($profile);

        return redirect()
            ->route('admin.intake.index')
            ->with('success', 'All pending intake suggestions were cleared.');
    }

    private function authorizeAdmin(): void
    {
        $u = auth()->user();
        if (! $u || ! $u->is_admin) {
            abort(403, 'Admin access required');
        }
    }
}
