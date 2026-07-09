<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBulkIntakeBatchItemJob;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\User;
use App\Services\EducationService;
use App\Services\Intake\BulkIntakeApplyPreviewService;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\BulkIntakeCandidateCorrectionService;
use App\Services\Intake\BulkIntakeCandidateDisplayService;
use App\Services\Intake\BulkIntakeEligibilityService;
use App\Services\Intake\BulkIntakeCandidateScreeningReviewService;
use App\Services\Intake\BulkIntakeDraftProfileBootstrapService;
use App\Services\Intake\BulkIntakeDuplicateHistoryHintService;
use App\Services\Intake\BulkIntakeManualTranscriptService;
use App\Services\Intake\BulkIntakeProgressPresenter;
use App\Services\Intake\BulkIntakeReadinessService;
use App\Services\Intake\IntakeOwnerAssignmentService;
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
        BulkIntakeBatchService $batchService
    ) {
        $validated = $request->validate([
            'batch_name' => ['nullable', 'string', 'max:255'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:20480'],
            'raw_text' => ['nullable', 'string'],
            'queue_free_parse_after_upload' => ['nullable', 'boolean'],
        ]);
        $queueFreeParseAfterUpload = array_key_exists('queue_free_parse_after_upload', $validated)
            ? $request->boolean('queue_free_parse_after_upload')
            : true;

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
                'parse_dispatch' => $queueFreeParseAfterUpload ? 'auto_free_parse_after_upload' : 'deferred',
            ],
        ]);

        $sequence = 1;
        foreach ($files as $file) {
            $item = $batchService->createPendingItemFromUploadedFile($batch, $file, $sequence, $queueFreeParseAfterUpload);
            ProcessBulkIntakeBatchItemJob::dispatch((int) $item->id, (int) $actor->id, $queueFreeParseAfterUpload)
                ->onQueue(ProcessBulkIntakeBatchItemJob::QUEUE_NAME);
            $sequence++;
        }

        foreach ($textItems as $rawText) {
            $item = $batchService->createPendingItemFromRawText($batch, $rawText, $sequence, $queueFreeParseAfterUpload);
            ProcessBulkIntakeBatchItemJob::dispatch((int) $item->id, (int) $actor->id, $queueFreeParseAfterUpload)
                ->onQueue(ProcessBulkIntakeBatchItemJob::QUEUE_NAME);
            $sequence++;
        }

        $batch = $batchService->refreshCounters($batch);

        return redirect()
            ->route('admin.bulk-intakes.show', $batch)
            ->with('success', 'Bulk intake queued. Items will process in background.');
    }

    public function show(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchService $batchService,
        BulkIntakeCandidateDisplayService $candidateDisplayService,
        BulkIntakeEligibilityService $eligibilityService,
        BulkIntakeDuplicateHistoryHintService $duplicateHistoryHintService,
        BulkIntakeProgressPresenter $progressPresenter
    )
    {
        $statusFilters = $this->bulkItemStatusFilters();
        $statusFilter = (string) $request->query('status', 'all');
        if (! array_key_exists($statusFilter, $statusFilters)) {
            $statusFilter = 'all';
        }

        $primaryScreeningFilters = $eligibilityService->primaryScreeningFilters();
        $legacyScreeningFilters = $eligibilityService->legacyScreeningFilters();
        $screeningFilter = $eligibilityService->resolveScreeningFilter((string) $request->query('screening', 'all'));

        $bulkIntakeBatch = $batchService->refreshCounters($bulkIntakeBatch);
        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');

        $itemsQuery = $bulkIntakeBatch->items()
            ->with([
                'biodataIntake:id,uploaded_by,matrimony_profile_id,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,approval_snapshot_json,reviewed_by_user_id,review_actor_type,review_surface,reviewed_at,approval_policy,approval_status,approved_at,content_hash,parsed_at,created_at,updated_at',
                'biodataIntake.uploadedByUser:id,name,email,mobile,is_admin,admin_role',
                'biodataIntake.uploadedByUser.matrimonyProfile:id,user_id',
            ])
            ->orderBy('item_sequence');

        $this->applyBulkItemStatusFilter($itemsQuery, $statusFilter);

        $statusFilteredItems = $itemsQuery->get();
        $candidateByItemId = $statusFilteredItems
            ->mapWithKeys(fn (BulkIntakeBatchItem $item): array => [
                (int) $item->id => $candidateDisplayService->candidateForItem($item),
            ])
            ->all();
        $duplicateHintsByItemId = $statusFilteredItems
            ->mapWithKeys(fn (BulkIntakeBatchItem $item): array => [
                (int) $item->id => $duplicateHistoryHintService->hintsForItem($item),
            ])
            ->all();
        $autoSuggestionByItemId = $statusFilteredItems
            ->mapWithKeys(fn (BulkIntakeBatchItem $item): array => [
                (int) $item->id => $eligibilityService->autoSuggestionForItem(
                    $item,
                    $candidateByItemId[(int) $item->id] ?? null,
                    $duplicateHintsByItemId[(int) $item->id] ?? []
                ),
            ])
            ->all();
        $screeningReviewByItemId = $statusFilteredItems
            ->mapWithKeys(fn (BulkIntakeBatchItem $item): array => [
                (int) $item->id => $eligibilityService->activeOverrideForItem($item),
            ])
            ->all();
        $readyForConsentByItemId = $statusFilteredItems
            ->mapWithKeys(fn (BulkIntakeBatchItem $item): array => [
                (int) $item->id => $eligibilityService->readyForConsentForItem(
                    $item,
                    $screeningReviewByItemId[(int) $item->id] ?? null,
                    $candidateByItemId[(int) $item->id] ?? null
                ),
            ])
            ->all();
        $defaultAutoSuggestion = [
            'decision' => 'review',
            'label' => 'Needs check',
            'reasons' => [],
            'reason_codes' => [],
            'suggested_next_action' => '',
        ];
        $screeningCounts = $eligibilityService->countsForItems(
            $statusFilteredItems,
            fn (BulkIntakeBatchItem $item): array => $autoSuggestionByItemId[(int) $item->id] ?? $defaultAutoSuggestion,
            fn (BulkIntakeBatchItem $item): ?array => $screeningReviewByItemId[(int) $item->id] ?? null,
            fn (BulkIntakeBatchItem $item): bool => (bool) ($readyForConsentByItemId[(int) $item->id]['ready'] ?? false),
        );
        $readyCount = (int) ($screeningCounts[BulkIntakeEligibilityService::FILTER_READY] ?? 0);
        $displayItems = $statusFilteredItems
            ->filter(fn (BulkIntakeBatchItem $item): bool => $eligibilityService->itemMatchesFilter(
                $screeningFilter,
                $screeningReviewByItemId[(int) $item->id] ?? null,
                $autoSuggestionByItemId[(int) $item->id] ?? $defaultAutoSuggestion,
                (bool) ($readyForConsentByItemId[(int) $item->id]['ready'] ?? false)
            ))
            ->values();
        $bulkIntakeBatch->setRelation('items', $displayItems);

        $sourceContextCountsByItem = IntakeSourceContext::query()
            ->where('bulk_intake_batch_id', $bulkIntakeBatch->id)
            ->whereNotNull('bulk_intake_batch_item_id')
            ->selectRaw('bulk_intake_batch_item_id, count(*) as aggregate')
            ->groupBy('bulk_intake_batch_item_id')
            ->pluck('aggregate', 'bulk_intake_batch_item_id');

        return view('admin.bulk-intakes.show', [
            'batch' => $bulkIntakeBatch,
            'sourceContextCountsByItem' => $sourceContextCountsByItem,
            'progress' => $progressPresenter->progressForBatch($bulkIntakeBatch),
            'candidateByItemId' => $candidateByItemId,
            'duplicateHintsByItemId' => $duplicateHintsByItemId,
            'autoSuggestionByItemId' => $autoSuggestionByItemId,
            'screeningByItemId' => $autoSuggestionByItemId,
            'screeningReviewByItemId' => $screeningReviewByItemId,
            'readyForConsentByItemId' => $readyForConsentByItemId,
            'readyCount' => $readyCount,
            'screeningFilter' => $screeningFilter,
            'primaryScreeningFilters' => $primaryScreeningFilters,
            'legacyScreeningFilters' => $legacyScreeningFilters,
            'screeningCounts' => $screeningCounts,
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

        $request->validate([
            'bootstrap_confirmed' => ['accepted'],
        ]);

        $bulkIntakeBatchItem->load('biodataIntake:id,uploaded_by,matrimony_profile_id,parse_status,approved_by_user,intake_locked,parsed_json');
        abort_unless($bulkIntakeBatchItem->biodataIntake !== null, 404);

        $result = $bootstrapService->bootstrapForItem($bulkIntakeBatchItem, $request->user());

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', 'Draft profile shell created for profile #'.$result['profile']->id.'. Parsed biodata fields were not applied.');
    }

    public function applyPreview(
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeApplyPreviewService $previewService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');
        $bulkIntakeBatchItem->load([
            'biodataIntake:id,uploaded_by,matrimony_profile_id,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,created_at',
            'biodataIntake.uploadedByUser:id,name,email,mobile,is_admin,admin_role',
            'biodataIntake.profile',
        ]);

        $preview = $previewService->previewForItem($bulkIntakeBatchItem);

        return view('admin.bulk-intakes.apply-preview', [
            'batch' => $bulkIntakeBatch,
            'item' => $bulkIntakeBatchItem,
            'intake' => $bulkIntakeBatchItem->biodataIntake,
            'profile' => $bulkIntakeBatchItem->biodataIntake?->profile,
            'preview' => $preview,
        ]);
    }

    public function manualTranscriptForm(BulkIntakeBatch $bulkIntakeBatch, BulkIntakeBatchItem $bulkIntakeBatchItem)
    {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');
        $bulkIntakeBatchItem->load([
            'biodataIntake:id,uploaded_by,matrimony_profile_id,original_filename,file_path,intake_status,parse_status,last_error,approved_by_user,intake_locked,parsed_json,raw_ocr_text,last_parse_input_text,created_at',
            'biodataIntake.uploadedByUser:id,name,email,mobile,is_admin,admin_role',
        ]);

        abort_unless($bulkIntakeBatchItem->biodataIntake !== null, 404);

        return view('admin.bulk-intakes.manual-transcript', [
            'batch' => $bulkIntakeBatch,
            'item' => $bulkIntakeBatchItem,
            'intake' => $bulkIntakeBatchItem->biodataIntake,
        ]);
    }

    public function storeManualTranscript(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeManualTranscriptService $manualTranscriptService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $validated = $request->validate([
            'transcript' => ['required', 'string', 'min:20', 'max:30000'],
            'queue_parse' => ['nullable', 'boolean'],
        ]);

        $bulkIntakeBatchItem->load('biodataIntake:id,uploaded_by,parse_status,last_error,approved_by_user,intake_locked,parsed_json,raw_ocr_text,last_parse_input_text');
        abort_unless($bulkIntakeBatchItem->biodataIntake !== null, 404);

        $result = $manualTranscriptService->saveTranscriptForItem(
            $bulkIntakeBatchItem,
            $request->user(),
            (string) $validated['transcript'],
            $request->boolean('queue_parse')
        );

        $message = $result['queued']
            ? 'Manual transcript saved and parse-input-only parse queued. Raw OCR text was not overwritten.'
            : 'Manual transcript saved as parse input evidence. Raw OCR text was not overwritten.';

        return redirect()
            ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
            ->with('success', $message);
    }

    public function correctCandidateForm(
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeCandidateCorrectionService $correctionService,
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $bulkIntakeBatch->load('uploadedByUser:id,name,email,mobile');
        $correction = $correctionService->correctionDataForItem($bulkIntakeBatchItem);
        abort_unless($correction['intake'] !== null, 404);

        return view('admin.bulk-intakes.correct-candidate', [
            'batch' => $bulkIntakeBatch,
            'item' => $bulkIntakeBatchItem,
            'intake' => $correction['intake'],
            'fields' => $correction['fields'],
            'sourceSnapshotSource' => $correction['source_snapshot_source'],
            'sourceText' => $correction['source_text'],
            'sourceTextLabel' => $correction['source_text_label'],
            'imagePreview' => $correction['image_preview'],
            'canSave' => $correction['can_save'],
        ]);
    }

    public function evidenceImage(
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeCandidateCorrectionService $correctionService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $response = $correctionService->evidenceImageResponse($bulkIntakeBatchItem);
        abort_unless($response !== null, 404);

        return $response;
    }

    public function saveCandidateCorrection(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeCandidateCorrectionService $correctionService,
        EducationService $educationService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:128'],
            'date_of_birth' => ['nullable', 'string', 'max:40'],
            'height' => ['nullable', 'string', 'max:40'],
            'height_cm' => ['nullable', 'integer', 'min:120', 'max:220'],
            'gender' => ['nullable', 'string', Rule::in(['', 'male', 'female', 'unknown'])],
            'education' => ['nullable', 'string', 'max:255'],
            'education_slots' => ['nullable'],
            'education_degree_ids' => ['nullable', 'array'],
            'education_degree_ids.*' => ['integer', 'min:1'],
            'education_custom' => ['nullable', 'array'],
            'education_custom.*' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'location_input' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'after_save' => ['nullable', Rule::in(['stay'])],
        ]);

        if ($educationService->mergeMultiselectEducationIntoRequest($request)) {
            $validated['education'] = $request->input('highest_education');
        }

        $correctionService->saveCorrection($bulkIntakeBatchItem, $request->user(), $validated);

        if (($validated['after_save'] ?? null) === 'stay') {
            return redirect()
                ->route('admin.bulk-intakes.items.correct-candidate', [$bulkIntakeBatch, $bulkIntakeBatchItem])
                ->with('success', 'Candidate correction saved as reviewed snapshot. Profile data was not modified.');
        }

        return redirect()
            ->route('admin.bulk-intakes.show', [
                'bulkIntakeBatch' => $bulkIntakeBatch,
                'highlight_item' => $bulkIntakeBatchItem->id,
            ])
            ->with('success', 'Candidate correction saved as reviewed snapshot. Profile data was not modified.');
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

    public function markItemManualDuplicate(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $validator = Validator::make($request->all(), [
            'matched_biodata_intake_id' => ['nullable', 'integer', 'exists:biodata_intakes,id'],
            'matched_profile_id' => ['nullable', 'integer', 'exists:matrimony_profiles,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $hasIntake = filled($request->input('matched_biodata_intake_id'));
            $hasProfile = filled($request->input('matched_profile_id'));
            $hasReason = trim((string) $request->input('reason', '')) !== '';
            if (! $hasIntake && ! $hasProfile && ! $hasReason) {
                $validator->errors()->add('duplicate_review', 'Provide a matched intake, matched profile, or reason.');
            }
        });
        $validated = $validator->validate();

        $meta = is_array($bulkIntakeBatchItem->item_meta_json) ? $bulkIntakeBatchItem->item_meta_json : [];
        $meta['duplicate_review'] = [
            'status' => 'manual_duplicate',
            'matched_biodata_intake_id' => isset($validated['matched_biodata_intake_id']) ? (int) $validated['matched_biodata_intake_id'] : null,
            'matched_profile_id' => isset($validated['matched_profile_id']) ? (int) $validated['matched_profile_id'] : null,
            'reason' => trim((string) ($validated['reason'] ?? '')) !== '' ? trim((string) $validated['reason']) : null,
            'marked_by_user_id' => (int) $request->user()->id,
            'marked_at' => now()->toISOString(),
            'cleared_by_user_id' => null,
            'cleared_at' => null,
        ];

        $bulkIntakeBatchItem->forceFill(['item_meta_json' => $meta])->save();

        return redirect()
            ->back()
            ->with('success', 'Bulk intake item marked as manual duplicate.');
    }

    public function saveItemScreeningReview(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeCandidateScreeningReviewService $screeningReviewService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $screeningReviewService->saveReview($bulkIntakeBatchItem, $request->user(), $request->all());

        return redirect()
            ->back()
            ->with('success', 'Manual screening decision saved.');
    }

    public function clearItemScreeningReview(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeCandidateScreeningReviewService $screeningReviewService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $screeningReviewService->clearReview($bulkIntakeBatchItem, $request->user());

        return redirect()
            ->back()
            ->with('success', 'Manual screening decision cleared.');
    }

    public function clearItemManualDuplicate(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);
        abort_unless($request->user() instanceof User, 403);

        $meta = is_array($bulkIntakeBatchItem->item_meta_json) ? $bulkIntakeBatchItem->item_meta_json : [];
        $existing = is_array($meta['duplicate_review'] ?? null) ? $meta['duplicate_review'] : [];
        $meta['duplicate_review'] = [
            'status' => 'cleared',
            'matched_biodata_intake_id' => isset($existing['matched_biodata_intake_id']) ? (int) $existing['matched_biodata_intake_id'] : null,
            'matched_profile_id' => isset($existing['matched_profile_id']) ? (int) $existing['matched_profile_id'] : null,
            'reason' => is_string($existing['reason'] ?? null) && trim($existing['reason']) !== '' ? trim($existing['reason']) : null,
            'marked_by_user_id' => isset($existing['marked_by_user_id']) ? (int) $existing['marked_by_user_id'] : null,
            'marked_at' => is_string($existing['marked_at'] ?? null) ? $existing['marked_at'] : null,
            'cleared_by_user_id' => (int) $request->user()->id,
            'cleared_at' => now()->toISOString(),
        ];

        $bulkIntakeBatchItem->forceFill(['item_meta_json' => $meta])->save();

        return redirect()
            ->back()
            ->with('success', 'Bulk intake item manual duplicate flag cleared.');
    }

    public function queueFreeParseItem(
        Request $request,
        BulkIntakeBatch $bulkIntakeBatch,
        BulkIntakeBatchItem $bulkIntakeBatchItem,
        BulkIntakeBatchService $batchService
    ) {
        abort_unless((int) $bulkIntakeBatchItem->bulk_intake_batch_id === (int) $bulkIntakeBatch->id, 404);

        $summary = $batchService->queueFreeParseForItem($bulkIntakeBatchItem, $request->user());

        if (($summary['skipped_reasons']['empty_ocr_text'] ?? 0) > 0) {
            return redirect()
                ->route('admin.bulk-intakes.show', $bulkIntakeBatch)
                ->with('error', 'Cannot queue free parse because OCR text is empty. Add manual transcript or re-upload clearer file.');
        }

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
            'pending' => 'Pending',
            'processing' => 'Processing',
            'intake_created' => 'Intake Created',
            'parse_queued' => 'Parse Queued',
            'parsed' => 'Parsed',
            'parse_error' => 'Parse Error',
            'needs_review' => 'Parse review',
            'failed' => 'Failed',
        ];
    }

    private function applyBulkItemStatusFilter($query, string $statusFilter): void
    {
        match ($statusFilter) {
            'pending' => $query->where('item_status', BulkIntakeBatchItem::STATUS_PENDING),
            'processing' => $query->where('item_status', BulkIntakeBatchItem::STATUS_PROCESSING),
            'intake_created' => $query->where('item_status', BulkIntakeBatchItem::STATUS_INTAKE_CREATED),
            'parse_queued' => $query->where('item_status', BulkIntakeBatchItem::STATUS_PARSE_QUEUED),
            'parsed' => $query->whereHas('biodataIntake', fn ($intakeQuery) => $intakeQuery->where('parse_status', 'parsed')),
            'parse_error' => $query->whereHas('biodataIntake', fn ($intakeQuery) => $intakeQuery->where('parse_status', 'error')),
            'needs_review' => $query->where('item_status', BulkIntakeBatchItem::STATUS_NEEDS_REVIEW),
            'failed' => $query->where('item_status', BulkIntakeBatchItem::STATUS_FAILED),
            default => null,
        };
    }
}
