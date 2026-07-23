<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakVerificationRecord;
use App\Modules\Suchak\Services\SuchakAccountLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Dedicated Suchak onboarding photo triage queue (profile / office / logo).
 * Approvals still go through AccountVerificationController + lifecycle service.
 *
 * Status filter (dropdown): pending | approved | rejected | all
 * Queue cards (optional): needs_review | auto_rejected | auto_passed | human_reviewed
 * These are independent — status is not the same as bulk photo checkboxes.
 */
class PhotoReviewController extends Controller
{
    public const QUEUE_NEEDS_REVIEW = 'needs_review';

    public const QUEUE_AUTO_REJECTED = 'auto_rejected';

    public const QUEUE_AUTO_PASSED = 'auto_passed';

    public const QUEUE_HUMAN_REVIEWED = 'human_reviewed';

    public const QUEUE_ALL = 'all';

    public const STATUS_ALL = 'all';

    /**
     * @return list<string>
     */
    public static function photoTypes(): array
    {
        return [
            SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
        ];
    }

    /**
     * @return list<string>
     */
    public static function queues(): array
    {
        return [
            self::QUEUE_NEEDS_REVIEW,
            self::QUEUE_AUTO_REJECTED,
            self::QUEUE_AUTO_PASSED,
            self::QUEUE_HUMAN_REVIEWED,
            self::QUEUE_ALL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            SuchakVerificationRecord::STATUS_PENDING,
            SuchakVerificationRecord::STATUS_APPROVED,
            SuchakVerificationRecord::STATUS_REJECTED,
            self::STATUS_ALL,
        ];
    }

    public function index(Request $request): View
    {
        $photoTypes = self::photoTypes();

        $status = (string) $request->query('status', SuchakVerificationRecord::STATUS_PENDING);
        $status = in_array($status, self::statuses(), true) ? $status : SuchakVerificationRecord::STATUS_PENDING;

        $queue = (string) $request->query('queue', self::QUEUE_ALL);
        $queue = in_array($queue, self::queues(), true) ? $queue : self::QUEUE_ALL;

        // Status dropdown is authoritative for admin_status. Queue cards only apply when Status = All,
        // otherwise AND-ing both creates empty/conflicting lists (e.g. Status=All + stale queue=auto_rejected).
        $effectiveQueue = $status === self::STATUS_ALL ? $queue : self::QUEUE_ALL;

        $type = $request->query('verification_type');
        $type = in_array($type, $photoTypes, true) ? $type : null;

        $records = self::photoBaseQuery()
            ->with(['suchakAccount.user', 'adminUser'])
            ->tap(fn (Builder $query) => self::applyStatusFilter($query, $status))
            ->tap(fn (Builder $query) => self::applyQueueFilter($query, $effectiveQueue))
            ->when($type, fn (Builder $query) => $query->where('verification_type', $type))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $fileMetaById = [];
        foreach ($records as $record) {
            $fileMetaById[$record->id] = self::resolveFileMeta($record);
        }

        return view('admin.suchak.photo-reviews.index', [
            'records' => $records,
            'photoTypes' => $photoTypes,
            'status' => $status,
            'queue' => $effectiveQueue,
            'type' => $type,
            'counts' => self::queueCounts(),
            'queues' => self::queues(),
            'statuses' => self::statuses(),
            'fileMetaById' => $fileMetaById,
        ]);
    }

    public function bulk(Request $request, SuchakAccountLifecycleService $lifecycleService): RedirectResponse
    {
        $validated = $request->validate([
            'record_ids' => ['required', 'array', 'min:1'],
            'record_ids.*' => ['integer'],
            'bulk_action' => ['required', 'string', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'return_status' => ['nullable', 'string'],
            'return_queue' => ['nullable', 'string'],
        ]);

        $adminStatus = $validated['bulk_action'] === 'approve'
            ? SuchakVerificationRecord::STATUS_APPROVED
            : SuchakVerificationRecord::STATUS_REJECTED;

        $records = self::photoBaseQuery()
            ->whereIn('id', $validated['record_ids'])
            ->with('suchakAccount')
            ->get();

        $ok = 0;
        $failed = 0;

        foreach ($records as $record) {
            try {
                $lifecycleService->updateVerificationRecordStatus(
                    $record,
                    $request->user(),
                    $adminStatus,
                    $validated['reason'],
                    $request->ip(),
                    Str::limit((string) $request->userAgent(), 512, ''),
                );
                $ok++;
            } catch (InvalidArgumentException) {
                $failed++;
            }
        }

        $params = [];
        $returnStatus = (string) ($validated['return_status'] ?? '');
        $returnQueue = (string) ($validated['return_queue'] ?? '');
        if (in_array($returnStatus, self::statuses(), true)) {
            $params['status'] = $returnStatus;
        }
        if (in_array($returnQueue, self::queues(), true)) {
            $params['queue'] = $returnQueue;
        }

        $message = $ok.' photo(s) updated.';
        if ($failed > 0) {
            $message .= ' '.$failed.' failed.';
        }

        return redirect()
            ->route('admin.suchak.photo-reviews.index', $params)
            ->with($failed > 0 && $ok === 0 ? 'error' : 'success', $message);
    }

    /**
     * @return array{needs_review: int, auto_rejected: int, auto_passed: int, human_reviewed: int}
     */
    public static function queueCounts(): array
    {
        return [
            self::QUEUE_NEEDS_REVIEW => self::photoBaseQuery()
                ->tap(fn (Builder $q) => self::applyQueueFilter($q, self::QUEUE_NEEDS_REVIEW))
                ->count(),
            self::QUEUE_AUTO_REJECTED => self::photoBaseQuery()
                ->tap(fn (Builder $q) => self::applyQueueFilter($q, self::QUEUE_AUTO_REJECTED))
                ->count(),
            self::QUEUE_AUTO_PASSED => self::photoBaseQuery()
                ->tap(fn (Builder $q) => self::applyQueueFilter($q, self::QUEUE_AUTO_PASSED))
                ->count(),
            self::QUEUE_HUMAN_REVIEWED => self::photoBaseQuery()
                ->tap(fn (Builder $q) => self::applyQueueFilter($q, self::QUEUE_HUMAN_REVIEWED))
                ->count(),
        ];
    }

    /**
     * Fast list metadata only — avoid getimagesize() on every row (slow on VPS disk).
     *
     * @return array{bytes: int|null, width: int|null, height: int|null, format: string|null, kb_label: string, dims_label: string, format_label: string}
     */
    public static function resolveFileMeta(SuchakVerificationRecord $record): array
    {
        $stored = is_array($record->file_meta) ? $record->file_meta : [];
        $bytes = isset($stored['bytes']) ? (int) $stored['bytes'] : null;
        $width = isset($stored['width']) ? (int) $stored['width'] : null;
        $height = isset($stored['height']) ? (int) $stored['height'] : null;
        $format = isset($stored['format']) ? strtolower((string) $stored['format']) : null;

        $path = trim((string) $record->document_path);
        if ($path !== '') {
            if ($format === null || $format === '') {
                $format = strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) ?: null;
            }
            if (($bytes === null || $bytes <= 0) && Storage::disk('local')->exists($path)) {
                $bytes = (int) Storage::disk('local')->size($path);
            }
        }

        if ($format === 'jpeg') {
            $format = 'jpg';
        }

        return [
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'format' => $format,
            'kb_label' => $bytes !== null && $bytes > 0
                ? rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.').' KB'
                : '—',
            'dims_label' => ($width && $height) ? $width.'×'.$height : '—',
            'format_label' => $format ? strtoupper($format) : '—',
        ];
    }

    private static function photoBaseQuery(): Builder
    {
        return SuchakVerificationRecord::query()
            ->whereIn('verification_type', self::photoTypes())
            ->whereNotNull('document_path')
            ->where('document_path', '!=', '');
    }

    private static function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status === self::STATUS_ALL) {
            return;
        }

        $query->where('admin_status', $status);
    }

    private static function applyQueueFilter(Builder $query, string $queue): void
    {
        match ($queue) {
            self::QUEUE_AUTO_REJECTED => $query
                ->where('admin_status', SuchakVerificationRecord::STATUS_REJECTED)
                ->where('moderation_decision', SuchakVerificationRecord::MODERATION_REJECTED)
                ->whereNull('admin_user_id'),
            self::QUEUE_AUTO_PASSED => $query
                ->where('admin_status', SuchakVerificationRecord::STATUS_APPROVED)
                ->where('moderation_decision', SuchakVerificationRecord::MODERATION_SAFE)
                ->whereNull('admin_user_id'),
            self::QUEUE_HUMAN_REVIEWED => $query
                ->whereNotNull('admin_user_id')
                ->whereIn('admin_status', [
                    SuchakVerificationRecord::STATUS_APPROVED,
                    SuchakVerificationRecord::STATUS_REJECTED,
                ]),
            self::QUEUE_NEEDS_REVIEW => $query->where('admin_status', SuchakVerificationRecord::STATUS_PENDING),
            default => null, // QUEUE_ALL — no extra filter
        };
    }
}
