<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakProfileRequest;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProfileRequestReplyController extends Controller
{
    public function store(
        Request $request,
        SuchakProfileRequest $profileRequest,
        SuchakRequestPipelineService $pipelineService,
    ): RedirectResponse {
        $validated = $request->validate([
            'reply_message' => ['required', 'string', 'max:1600'],
        ]);

        $account = $request->user()
            ->suchakAccount()
            ->firstOrFail();

        try {
            $pipelineService->replyThroughExistingChat(
                $profileRequest,
                $account,
                $request->user(),
                (string) $validated['reply_message'],
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('suchak.dashboard', ['dashboard_tab' => 'requests'])
            ->with('success', 'Reply sent to the member chat.');
    }
}
