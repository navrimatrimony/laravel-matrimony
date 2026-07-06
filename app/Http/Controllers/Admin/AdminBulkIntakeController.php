<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeSourceContextRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminBulkIntakeController extends Controller
{
    public function index(Request $request)
    {
        $status = trim((string) $request->query('status', ''));
        $batches = BulkIntakeBatch::query()
            ->with('uploadedByUser:id,name,email')
            ->when($status !== '', fn ($query) => $query->where('batch_status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.bulk-intakes.index', [
            'batches' => $batches,
            'status' => $status,
            'statuses' => [
                BulkIntakeBatch::STATUS_PENDING,
                BulkIntakeBatch::STATUS_PROCESSING,
                BulkIntakeBatch::STATUS_COMPLETED,
                BulkIntakeBatch::STATUS_FAILED,
                BulkIntakeBatch::STATUS_CANCELLED,
            ],
        ]);
    }

    public function create()
    {
        $users = User::query()
            ->where(function ($query): void {
                $query->where('is_admin', false)->orWhereNull('is_admin');
            })
            ->whereNull('admin_role')
            ->latest()
            ->limit(500)
            ->get(['id', 'name', 'email', 'mobile']);

        return view('admin.bulk-intakes.create', ['users' => $users]);
    }

    public function store(
        Request $request,
        BulkIntakeBatchService $batchService,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder
    ) {
        $validated = $request->validate([
            'batch_name' => ['nullable', 'string', 'max:255'],
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:20480'],
            'raw_text' => ['nullable', 'string'],
        ]);

        $ownerUser = User::query()->findOrFail((int) $validated['owner_user_id']);
        if ($ownerUser->isAnyAdmin()) {
            throw ValidationException::withMessages([
                'owner_user_id' => 'Select a non-admin member account.',
            ]);
        }

        $files = array_values(array_filter($request->file('files', [])));
        $textItems = $this->splitTextItems((string) ($validated['raw_text'] ?? ''));
        if ($files === [] && $textItems === []) {
            throw ValidationException::withMessages([
                'raw_text' => 'Add at least one biodata file or one text item.',
            ]);
        }

        $actor = $request->user();
        $batch = $batchService->createBatch([
            'uploaded_by_user_id' => $actor?->id,
            'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
            'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
            'batch_name' => $validated['batch_name'] ?? null,
            'batch_status' => BulkIntakeBatch::STATUS_PENDING,
            'intake_creation_policy' => BulkIntakeBatch::POLICY_EXISTING_CHAIN,
            'ocr_policy' => BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
            'meta_json' => [
                'owner_user_id' => (int) $ownerUser->id,
                'owner_user_mode' => 'existing_user_for_whole_batch',
                'parse_dispatch' => 'deferred',
            ],
        ]);

        $batch = $batchService->processExistingUserBatch(
            $batch,
            $ownerUser,
            $files,
            $textItems,
            $actor,
            $intakeCreation,
            $sourceContextRecorder
        );

        return redirect()
            ->route('admin.bulk-intakes.show', $batch)
            ->with('success', 'Bulk intake batch processed. Created biodata intake rows only; parsing and profile apply remain separate.');
    }

    public function show(BulkIntakeBatch $bulkIntakeBatch)
    {
        $bulkIntakeBatch->load([
            'uploadedByUser:id,name,email',
            'items' => fn ($query) => $query->orderBy('item_sequence'),
            'items.biodataIntake:id,uploaded_by,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,created_at',
        ]);

        $sourceContextCountsByItem = IntakeSourceContext::query()
            ->where('bulk_intake_batch_id', $bulkIntakeBatch->id)
            ->whereNotNull('bulk_intake_batch_item_id')
            ->selectRaw('bulk_intake_batch_item_id, count(*) as aggregate')
            ->groupBy('bulk_intake_batch_item_id')
            ->pluck('aggregate', 'bulk_intake_batch_item_id');

        return view('admin.bulk-intakes.show', [
            'batch' => $bulkIntakeBatch,
            'sourceContextCountsByItem' => $sourceContextCountsByItem,
        ]);
    }

    public function queueFreeParse(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchService $batchService
    ) {
        $validated = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ]);

        $summary = $batchService->queueFreeParseForBatch(
            $bulkIntakeBatch,
            $request->user(),
            $validated['item_ids'] ?? null
        );

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', $this->queueSummaryMessage($summary));
    }

    public function queueFreeParseItem(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeBatchService $batchService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $summary = $batchService->queueFreeParseForItem($bulkIntakeBatchItem, $request->user());

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', $this->queueSummaryMessage($summary));
    }

    /**
     * @return list<string>
     */
    private function splitTextItems(string $rawText): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return [];
        }

        $parts = preg_split('/^\s*---INTAKE---\s*$/m', $rawText) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== ''
        ));
    }

    /**
     * @param  array{queued: int, skipped: int, failed: int, skipped_reasons: array<string, int>}  $summary
     */
    private function queueSummaryMessage(array $summary): string
    {
        $message = "Free parse queued: {$summary['queued']}; skipped: {$summary['skipped']}; failed: {$summary['failed']}.";
        if ($summary['skipped_reasons'] !== []) {
            $reasons = collect($summary['skipped_reasons'])
                ->map(fn (int $count, string $reason): string => $reason.'='.$count)
                ->implode(', ');

            return $message.' Skipped reasons: '.$reasons.'.';
        }

        return $message;
    }
}
