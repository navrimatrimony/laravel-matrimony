<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakVerificationRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Dedicated Suchak onboarding photo triage queue (profile / office / logo).
 * Approvals still go through AccountVerificationController + lifecycle service.
 *
 * Queues:
 * - needs_review: human must decide (AI review / AI error / legacy pending)
 * - auto_rejected: AI unsafe, not yet human-overridden
 * - auto_passed: AI safe auto-approved
 * - human_reviewed: admin approved/rejected (history)
 */
class PhotoReviewController extends Controller
{
    public const QUEUE_NEEDS_REVIEW = 'needs_review';

    public const QUEUE_AUTO_REJECTED = 'auto_rejected';

    public const QUEUE_AUTO_PASSED = 'auto_passed';

    public const QUEUE_HUMAN_REVIEWED = 'human_reviewed';

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
        ];
    }

    public function index(Request $request): View
    {
        $photoTypes = self::photoTypes();
        $queue = (string) $request->query('queue', self::QUEUE_NEEDS_REVIEW);
        $queue = in_array($queue, self::queues(), true) ? $queue : self::QUEUE_NEEDS_REVIEW;

        $type = $request->query('verification_type');
        $type = in_array($type, $photoTypes, true) ? $type : null;

        $records = self::photoBaseQuery()
            ->with(['suchakAccount.user', 'adminUser'])
            ->tap(fn (Builder $query) => self::applyQueueFilter($query, $queue))
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
            'queue' => $queue,
            'type' => $type,
            'counts' => self::queueCounts(),
            'queues' => self::queues(),
            'fileMetaById' => $fileMetaById,
        ]);
    }

    /**
     * @return array{
     *     needs_review: int,
     *     auto_rejected: int,
     *     auto_passed: int,
     *     human_reviewed: int
     * }
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
        if ($path !== '' && Storage::disk('local')->exists($path)) {
            if ($bytes === null || $bytes <= 0) {
                $bytes = (int) Storage::disk('local')->size($path);
            }
            if ($format === null || $format === '') {
                $format = strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) ?: null;
            }
            if (($width === null || $height === null) && in_array($format, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $absolute = Storage::disk('local')->path($path);
                $info = @getimagesize($absolute);
                if (is_array($info)) {
                    $width = $width ?? (int) ($info[0] ?? 0) ?: null;
                    $height = $height ?? (int) ($info[1] ?? 0) ?: null;
                }
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
            // Pending = needs human review (includes legacy rows without moderation_decision).
            default => $query->where('admin_status', SuchakVerificationRecord::STATUS_PENDING),
        };
    }
}
