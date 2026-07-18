<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrmLedgerService;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Thin adapters: CRM note + biodata PDF/QR export over existing services.
 */
class SuchakCustomerOpsApiController extends Controller
{
    public function storeNote(
        Request $request,
        int $representation,
        SuchakCrmLedgerService $crmLedgerService,
    ): JsonResponse {
        [$user, $account, $rep] = $this->ownedRepresentation($request, $representation);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'note_text' => ['required', 'string', 'max:4000'],
            'note_type' => ['nullable', 'string', Rule::in(SuchakProfileNote::TYPES)],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        try {
            $note = $crmLedgerService->createProfileNote(
                $account,
                $user,
                $rep->matrimonyProfile()->firstOrFail(),
                [
                    'note_type' => $validated['note_type'] ?? SuchakProfileNote::TYPE_GENERAL,
                    'note_text' => $validated['note_text'],
                    'follow_up_at' => $validated['follow_up_at'] ?? null,
                ],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'CRM note saved.',
            'data' => [
                'note_id' => $note->id,
                'note_type' => $note->note_type,
            ],
        ], 201);
    }

    public function exportBiodata(
        Request $request,
        int $representation,
        SuchakPdfQrFoundationService $exportService,
    ): JsonResponse {
        [$user, $account, $rep] = $this->ownedRepresentation($request, $representation);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $result = $exportService->createGovernedBiodataPdfExport(
                $rep,
                $user,
                null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $export = $result['export'];
        $qrUrlPath = $result['qr_url_path'] ?? null;
        $qrAbsolute = is_string($qrUrlPath) && $qrUrlPath !== ''
            ? url($qrUrlPath)
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Biodata PDF/QR export generated.',
            'data' => [
                'export_id' => $export->id,
                'qr_url' => $qrAbsolute,
                'qr_url_path' => $qrUrlPath,
                'file_ready' => filled($export->file_path) && Storage::disk('local')->exists((string) $export->file_path),
            ],
        ], 201);
    }

    /**
     * @return array{0: User|JsonResponse, 1: ?SuchakAccount, 2: ?SuchakProfileRepresentation}
     */
    private function ownedRepresentation(Request $request, int $representationId): array
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return [
                response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403),
                null,
                null,
            ];
        }

        $account = $user->suchakAccount;
        $rep = SuchakProfileRepresentation::query()
            ->whereKey($representationId)
            ->where('suchak_account_id', $account->id)
            ->first();

        if ($rep === null) {
            return [
                response()->json(['success' => false, 'message' => 'Customer not found for this Suchak account.'], 404),
                null,
                null,
            ];
        }

        return [$user, $account, $rep];
    }
}
