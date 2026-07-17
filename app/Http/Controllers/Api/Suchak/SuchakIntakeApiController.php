<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakSourceLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Thin mobile adapter over SuchakSourceLinkService::createFromIntakeUpload.
 */
class SuchakIntakeApiController extends Controller
{
    public function store(Request $request, SuchakSourceLinkService $sourceLinkService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null || ! $sourceLinkService->canCreate($account)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active Suchak accounts can create biodata intake source links.',
            ], 403);
        }

        $validated = $request->validate([
            'raw_text' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:raw_text'],
        ]);

        try {
            $link = $sourceLinkService->createFromIntakeUpload(
                $account,
                $user,
                $request->file('file'),
                $validated['raw_text'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Suchak biodata intake source link created.',
            'data' => [
                'source_link_id' => $link->id,
                'biodata_intake_id' => $link->biodata_intake_id,
                'source_status' => $link->source_status,
            ],
        ], 201);
    }
}
