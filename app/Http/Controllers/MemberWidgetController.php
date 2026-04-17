<?php

namespace App\Http\Controllers;

use App\Services\MemberQuickHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberWidgetController extends Controller
{
    public function counts(Request $request, MemberQuickHubService $service): JsonResponse
    {
        return response()->json([
            'ok' => true,
            ...$service->buildLiveCountsForUser($request->user()),
        ]);
    }

    public function chatDock(Request $request, MemberQuickHubService $service): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'chat_dock' => $service->buildChatDockSnapshotForUser($request->user()),
        ]);
    }
}
