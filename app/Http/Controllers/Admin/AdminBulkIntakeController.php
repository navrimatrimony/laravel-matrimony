<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\BulkIntakeDraftProfileBootstrapService;
use App\Services\Intake\BulkIntakeReadinessService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeOwnerAssignmentService;
use App\Services\Intake\IntakeSourceContextRecorder;
use App\Support\MobileNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        return view('admin.bulk-intakes.create');
    }

    public function store(
        Request $request,
        BulkIntakeBatchService $batchService,
        IntakeCreationService $intakeCreation,
        IntakeSourceContextRecorder $sourceContextRecorder
    ) {
        $validated = $request->validate([
            'batch_name' => ['nullable', 'string', 'max:255'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:20480'],
            'raw_text' => ['nullable', 'string'],
        ]);

        $files = array_values(array_filter($request->file('files', [])));
        $textItems = $this->splitTextItems((string) ($validated['raw_text'] ?? ''));
        if ($files === [] && $textItems === []) {
            throw ValidationException::withMessages([
                'raw_text' => 'Add at least one biodata file or one text item.',
            ]);
        }

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $batch = $batchService->createBatch([
            'uploaded_by_user_id' => $actor?->id,
            'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
            'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
            'batch_name' => $validated['batch_name'] ?? null,
            'batch_status' => BulkIntakeBatch::STATUS_PENDING,
            'intake_creation_policy' => BulkIntakeBatch::POLICY_EXISTING_CHAIN,
            'ocr_policy' => BulkIntakeBatch::OCR_POLICY_FREE_OCR_FIRST,
            'meta_json' => [
                'owner_user_id' => null,
                'candidate_user_id' => null,
                'owner_user_mode' => 'unclaimed_bulk_staging',
                'consent_status' => 'pending',
                'profile_creation_policy' => 'after_candidate_consent',
                'parse_dispatch' => 'deferred',
            ],
        ]);

        $batch = $batchService->processUnclaimedBulkBatch(
            $batch,
            $files,
            $textItems,
            $actor,
            $intakeCreation,
            $sourceContextRecorder
        );

        return redirect()
            ->route('admin.bulk-intakes.show', $batch)
            ->with('success', 'Bulk intake batch processed as unclaimed staging. Parsing and profile apply remain separate.');
    }

    public function show(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchService $batchService,
        BulkIntakeReadinessService $readinessService
    )
    {
        $statusFilters = $this->bulkItemStatusFilters();
        $statusFilter = (string) $request->query('status', 'all');
        if (! array_key_exists($statusFilter, $statusFilters)) {
            $statusFilter = 'all';
        }

        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');
        $readinessReport = $readinessService->readinessForBatch($bulkIntakeBatch);

        $itemsQuery = $bulkIntakeBatch->items()
            ->with([
                'biodataIntake:id,uploaded_by,matrimony_profile_id,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,created_at',
                'biodataIntake.uploadedByUser:id,name,email,mobile,is_admin,admin_role',
                'biodataIntake.uploadedByUser.matrimonyProfile:id,user_id',
            ])
            ->orderBy('item_sequence');

        if (! in_array($statusFilter, ['ready', 'not_ready', 'blocked'], true)) {
            $this->applyBulkItemStatusFilter($itemsQuery, $statusFilter);
        }

        $items = $itemsQuery->get();
        if (in_array($statusFilter, ['ready', 'not_ready', 'blocked'], true)) {
            $targetStatus = $statusFilter === 'ready' ? 'ready_for_profile_review' : $statusFilter;
            $items = $items
                ->filter(fn (BulkIntakeBatchItem $item): bool => ($readinessReport['by_item_id'][(int) $item->id]['status'] ?? null) === $targetStatus)
                ->values();
        }
        $bulkIntakeBatch->setRelation('items', $items);

        $sourceContextCountsByItem = IntakeSourceContext::query()
            ->where('bulk_intake_batch_id', $bulkIntakeBatch->id)
            ->whereNotNull('bulk_intake_batch_item_id')
            ->selectRaw('bulk_intake_batch_item_id, count(*) as aggregate')
            ->groupBy('bulk_intake_batch_item_id')
            ->pluck('aggregate', 'bulk_intake_batch_item_id');

        return view('admin.bulk-intakes.show', [
            'batch' => $bulkIntakeBatch,
            'sourceContextCountsByItem' => $sourceContextCountsByItem,
            'reviewSummary' => $batchService->buildBatchReviewSummary($bulkIntakeBatch),
            'readinessByItem' => $readinessReport['by_item_id'],
            'readinessSummary' => $readinessReport['summary'],
            'statusFilter' => $statusFilter,
            'statusFilters' => $statusFilters,
        ]);
    }

    public function readiness(
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeReadinessService $readinessService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');
        $bulkIntakeBatchItem->load([
            'biodataIntake:id,uploaded_by,matrimony_profile_id,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,created_at',
            'biodataIntake.uploadedByUser:id,name,email,mobile,is_admin,admin_role',
            'biodataIntake.uploadedByUser.matrimonyProfile:id,user_id',
        ]);

        return view('admin.bulk-intakes.readiness', [
            'batch' => $bulkIntakeBatch,
            'item' => $bulkIntakeBatchItem,
            'intake' => $bulkIntakeBatchItem->biodataIntake,
            'readiness' => $readinessService->readinessForItem($bulkIntakeBatchItem),
        ]);
    }

    public function bootstrapDraftProfile(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeDraftProfileBootstrapService $bootstrapService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $validated = $request->validate([
            'bootstrap_confirmed' => ['accepted'],
        ]);

        $bulkIntakeBatchItem->load('biodataIntake:id,uploaded_by,matrimony_profile_id,parse_status,approved_by_user,intake_locked,parsed_json');
        abort_unless($bulkIntakeBatchItem->biodataIntake !== null, 404);

        $result = $bootstrapService->bootstrapForItem($bulkIntakeBatchItem, $request->user());

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', 'Draft profile shell created for profile #'.$result['profile']->id.'. Parsed biodata fields were not applied.');
    }

    public function assignOwnerForm(BulkIntakeBatch $bulkIntakeBatch, BulkIntakeBatchItem $bulkIntakeBatchItem)
    {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');
        $bulkIntakeBatchItem->load('biodataIntake:id,uploaded_by,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,created_at');
        $intake = $bulkIntakeBatchItem->biodataIntake;

        abort_unless($intake !== null, 404);

        if ($intake->uploaded_by !== null) {
            return redirect()
                ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
                ->with('error', 'Owner already assigned. Reassignment is not available in this phase.');
        }

        return view('admin.bulk-intakes.assign-owner', [
            'batch' => $bulkIntakeBatch,
            'item' => $bulkIntakeBatchItem,
            'intake' => $intake,
        ]);
    }

    public function assignOwner(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        IntakeOwnerAssignmentService $ownerAssignmentService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $bulkIntakeBatchItem->load('biodataIntake:id,uploaded_by,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,created_at');
        $intake = $bulkIntakeBatchItem->biodataIntake;
        abort_unless($intake !== null, 404);

        if ($intake->uploaded_by !== null) {
            return redirect()
                ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
                ->with('error', 'Owner already assigned. Reassignment is not available in this phase.');
        }

        $validated = $request->validate([
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'consent_confirmed' => ['accepted'],
            'consent_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $ownerUser = User::query()->findOrFail((int) $validated['owner_user_id']);

        $ownerAssignmentService->assignExistingUserToUnclaimedIntake($intake, $ownerUser, $request->user(), [
            'bulk_intake_batch_id' => $bulkIntakeBatch->id,
            'bulk_intake_batch_item_id' => $bulkIntakeBatchItem->id,
            'consent_note' => $validated['consent_note'] ?? null,
        ]);

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', 'Owner assigned to intake after consent confirmation.');
    }

    public function createOwnerForm(BulkIntakeBatch $bulkIntakeBatch, BulkIntakeBatchItem $bulkIntakeBatchItem)
    {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');
        $bulkIntakeBatchItem->load('biodataIntake:id,uploaded_by,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,created_at');
        $intake = $bulkIntakeBatchItem->biodataIntake;

        abort_unless($intake !== null, 404);

        if ($intake->uploaded_by !== null) {
            return redirect()
                ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
                ->with('error', 'Owner already assigned. Reassignment is not available in this phase.');
        }

        return view('admin.bulk-intakes.create-owner', [
            'batch' => $bulkIntakeBatch,
            'item' => $bulkIntakeBatchItem,
            'intake' => $intake,
        ]);
    }

    public function createOwner(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        IntakeOwnerAssignmentService $ownerAssignmentService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $bulkIntakeBatchItem->load('biodataIntake:id,uploaded_by,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,created_at');
        $intake = $bulkIntakeBatchItem->biodataIntake;
        abort_unless($intake !== null, 404);

        if ($intake->uploaded_by !== null) {
            return redirect()
                ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
                ->with('error', 'Owner already assigned. Reassignment is not available in this phase.');
        }

        $validated = $request->validate([
            'new_name' => ['required', 'string', 'max:255'],
            'new_mobile' => ['required', 'string', 'max:32'],
            'new_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'registering_for' => ['required', Rule::in(['self', 'parent_guardian', 'sibling', 'relative', 'friend', 'other'])],
            'consent_confirmed' => ['accepted'],
            'consent_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $mobile = MobileNumber::normalize($validated['new_mobile']);
        if ($mobile === null) {
            return back()
                ->withInput()
                ->withErrors(['new_mobile' => __('otp.enter_valid_10_digit_mobile')]);
        }

        Validator::make(
            ['new_mobile' => $mobile],
            ['new_mobile' => ['required', Rule::unique('users', 'mobile')]],
            ['new_mobile.unique' => __('auth.mobile_duplicate_register')]
        )->validate();

        $ownerAssignmentService->createMemberAndAssignToUnclaimedIntake($intake, $request->user(), [
            'name' => $validated['new_name'],
            'email' => $validated['new_email'] ?? null,
            'mobile' => $mobile,
            'registering_for' => $validated['registering_for'],
        ], [
            'bulk_intake_batch_id' => $bulkIntakeBatch->id,
            'bulk_intake_batch_item_id' => $bulkIntakeBatchItem->id,
            'consent_note' => $validated['consent_note'] ?? null,
        ]);

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', 'New member owner created and assigned after consent confirmation.');
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

    public function markItemNeedsReview(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeBatchService $batchService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $batchService->markItemNeedsReview($bulkIntakeBatchItem, $request->user(), $validated['reason'] ?? null);

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', 'Bulk intake item marked as needs review.');
    }

    public function clearItemNeedsReview(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeBatchService $batchService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $batchService->clearItemNeedsReview($bulkIntakeBatchItem, $request->user());

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', 'Bulk intake item review flag cleared.');
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

    /**
     * @return array<string, string>
     */
    private function bulkItemStatusFilters(): array
    {
        return [
            'all' => 'All',
            'unclaimed' => 'Unclaimed',
            'pending' => 'Pending',
            'parse_queued' => 'Parse Queued',
            'parsed' => 'Parsed',
            'parse_error' => 'Parse Error',
            'needs_review' => 'Needs Review',
            'failed' => 'Failed',
            'ready' => 'Ready',
            'not_ready' => 'Not Ready',
            'blocked' => 'Blocked',
        ];
    }

    private function applyBulkItemStatusFilter($query, string $statusFilter): void
    {
        match ($statusFilter) {
            'unclaimed' => $query->whereHas('biodataIntake', fn ($intakeQuery) => $intakeQuery->whereNull('uploaded_by')),
            'pending' => $query->where(function ($nested): void {
                $nested
                    ->where('item_status', BulkIntakeBatchItem::STATUS_PENDING)
                    ->orWhereHas('biodataIntake', fn ($intakeQuery) => $intakeQuery->where('parse_status', 'pending'));
            }),
            'parse_queued' => $query->where('item_status', BulkIntakeBatchItem::STATUS_PARSE_QUEUED),
            'parsed' => $query->whereHas('biodataIntake', fn ($intakeQuery) => $intakeQuery->where('parse_status', 'parsed')),
            'parse_error' => $query->whereHas('biodataIntake', fn ($intakeQuery) => $intakeQuery->where('parse_status', 'error')),
            'needs_review' => $query->where('item_status', BulkIntakeBatchItem::STATUS_NEEDS_REVIEW),
            'failed' => $query->where('item_status', BulkIntakeBatchItem::STATUS_FAILED),
            default => null,
        };
    }
}
